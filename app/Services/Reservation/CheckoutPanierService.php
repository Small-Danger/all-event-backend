<?php

namespace App\Services\Reservation;

use App\Models\Billet;
use App\Models\Creneau;
use App\Models\LigneReservation;
use App\Models\Panier;
use App\Models\Paiement;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CheckoutPanierService
{
    /**
     * Transforme le panier actif en réservation + paiement en attente + billet (hors Stripe).
     *
     * @return array{reservation: Reservation, paiement: Paiement, billet: Billet}
     */
    public function executer(User $user, Panier $panier, ?int $promotionId = null): array
    {
        if ($panier->user_id !== $user->id) {
            throw new InvalidArgumentException('Panier invalide.');
        }

        if ($panier->statut !== 'actif') {
            throw new InvalidArgumentException('Ce panier ne peut pas etre valide.');
        }

        $lignes = $panier->lignes()->with('creneau.activite')->get();
        if ($lignes->isEmpty()) {
            throw new InvalidArgumentException('Panier vide.');
        }

        return DB::transaction(function () use ($user, $panier, $lignes, $promotionId) {
            $sousTotal = '0.00';

            foreach ($lignes as $ligne) {
                $creneau = Creneau::query()
                    ->whereKey($ligne->creneau_id)
                    ->with('activite')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($creneau->statut !== 'ouvert') {
                    throw new InvalidArgumentException('Un creneau nest plus disponible.');
                }

                if ($creneau->capacite_restante < $ligne->quantite) {
                    throw new InvalidArgumentException('Places insuffisantes pour un creneau.');
                }

                if ($creneau->activite->statut !== 'publiee') {
                    throw new InvalidArgumentException('Activite non publiee.');
                }

                $sousTotal = $this->bcAdd(
                    $sousTotal,
                    $this->bcMul((string) $ligne->prix_unitaire_snapshot, (string) $ligne->quantite, 2),
                    2
                );
            }

            $montantReduction = '0.00';
            $promotion = null;
            if ($promotionId !== null) {
                $promotion = Promotion::query()->whereKey($promotionId)->lockForUpdate()->first();
                if (! $promotion) {
                    throw new InvalidArgumentException('Promotion introuvable.');
                }
                $montantReduction = $this->calculerReduction($promotion, $sousTotal, $lignes);
            }

            $montantTotal = $this->bcSub($sousTotal, $montantReduction, 2);
            if ($this->bcComp($montantTotal, '0', 2) < 0) {
                $montantTotal = '0.00';
            }

            $reservation = Reservation::create([
                'user_id' => $user->id,
                'panier_id' => $panier->id,
                'promotion_id' => $promotion?->id,
                'statut' => 'en_attente_paiement',
                'montant_total' => $montantTotal,
                'montant_reduction' => $montantReduction !== '0.00' ? $montantReduction : null,
                'devise' => 'MAD',
            ]);

            foreach ($lignes as $ligne) {
                $creneau = Creneau::query()
                    ->whereKey($ligne->creneau_id)
                    ->with('activite')
                    ->lockForUpdate()
                    ->firstOrFail();
                $creneau->decrement('capacite_restante', $ligne->quantite);

                LigneReservation::create([
                    'reservation_id' => $reservation->id,
                    'creneau_id' => $ligne->creneau_id,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_snapshot' => $ligne->prix_unitaire_snapshot,
                ]);
            }

            $panier->lignes()->delete();
            $panier->update(['statut' => 'converti']);

            $paiement = Paiement::create([
                'reservation_id' => $reservation->id,
                'montant' => $montantTotal,
                'devise' => 'MAD',
                'statut' => 'en_attente',
            ]);

            $billet = Billet::create([
                'reservation_id' => $reservation->id,
                'code_public' => Str::upper(Str::random(12)),
                'payload_qr' => null,
                'statut' => 'emis',
                'emis_le' => now(),
            ]);

            if ($promotion) {
                $promotion->increment('utilisations_actuelles');
            }

            return [
                'reservation' => $reservation->fresh(),
                'paiement' => $paiement,
                'billet' => $billet,
            ];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\LignePanier>  $lignes
     */
    private function calculerReduction(Promotion $promotion, string $sousTotal, $lignes): string
    {
        if ($promotion->statut !== 'active') {
            throw new InvalidArgumentException('Promotion inactive.');
        }
        $now = now();
        if ($now->lt($promotion->debut_at) || $now->gt($promotion->fin_at)) {
            throw new InvalidArgumentException('Promotion non valide a cette date.');
        }
        if ($promotion->utilisations_max !== null && $promotion->utilisations_actuelles >= $promotion->utilisations_max) {
            throw new InvalidArgumentException('Promotion epuisee.');
        }

        if ($promotion->prestataire_id !== null) {
            foreach ($lignes as $ligne) {
                $ligne->creneau->loadMissing('activite');
                if ((int) $ligne->creneau->activite->prestataire_id !== (int) $promotion->prestataire_id) {
                    throw new InvalidArgumentException('Cette promotion ne sapplique pas a ces activites.');
                }
            }
        }

        if ($promotion->activite_id !== null) {
            $ok = $lignes->contains(fn ($l) => (int) $l->creneau->activite_id === (int) $promotion->activite_id);
            if (! $ok) {
                throw new InvalidArgumentException('Cette promotion ne sapplique pas au panier.');
            }
        }

        $min = $promotion->montant_minimum_commande;
        if ($min !== null && $this->bcComp($sousTotal, (string) $min, 2) < 0) {
            throw new InvalidArgumentException('Montant minimum non atteint pour la promotion.');
        }

        $reduction = '0.00';
        if ($promotion->type_remise === 'pourcentage') {
            $reduction = $this->bcMul($sousTotal, $this->bcDiv((string) $promotion->valeur, '100', 4), 2);
        } elseif ($promotion->type_remise === 'montant_fixe') {
            $reduction = (string) $promotion->valeur;
        } else {
            throw new InvalidArgumentException('Type de remise inconnu.');
        }

        $plafond = $promotion->reduction_plafond;
        if ($plafond !== null && $this->bcComp($reduction, (string) $plafond, 2) > 0) {
            $reduction = (string) $plafond;
        }

        if ($this->bcComp($reduction, $sousTotal, 2) > 0) {
            $reduction = $sousTotal;
        }

        return $reduction;
    }

    private function bcAdd(string $left, string $right, int $scale = 2): string
    {
        if (function_exists('bcadd')) {
            return bcadd($left, $right, $scale);
        }

        return number_format(((float) $left) + ((float) $right), $scale, '.', '');
    }

    private function bcSub(string $left, string $right, int $scale = 2): string
    {
        if (function_exists('bcsub')) {
            return bcsub($left, $right, $scale);
        }

        return number_format(((float) $left) - ((float) $right), $scale, '.', '');
    }

    private function bcMul(string $left, string $right, int $scale = 2): string
    {
        if (function_exists('bcmul')) {
            return bcmul($left, $right, $scale);
        }

        return number_format(((float) $left) * ((float) $right), $scale, '.', '');
    }

    private function bcDiv(string $left, string $right, int $scale = 2): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv($left, $right, $scale);
        }

        $divisor = (float) $right;
        if ($divisor == 0.0) {
            throw new InvalidArgumentException('Division par zero.');
        }

        return number_format(((float) $left) / $divisor, $scale, '.', '');
    }

    private function bcComp(string $left, string $right, int $scale = 2): int
    {
        if (function_exists('bccomp')) {
            return bccomp($left, $right, $scale);
        }

        $l = round((float) $left, $scale);
        $r = round((float) $right, $scale);

        return $l <=> $r;
    }
}
