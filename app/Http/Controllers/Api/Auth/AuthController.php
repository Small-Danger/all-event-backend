<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Mail\OtpActivationMail;
use App\Mail\PrestataireUnderReviewMail;
use App\Models\EmailOtp;
use App\Models\IdentifiantBloque;
use App\Models\Prestataire;
use App\Models\PrestataireDocument;
use App\Models\PrestataireMembre;
use App\Models\Profil;
use App\Models\User;
use App\Services\TransactionalMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestion de l'authentification API.
 * Endpoints: inscription, connexion, deconnexion et recuperation utilisateur courant.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly TransactionalMailer $transactionalMailer,
    ) {}

    /**
     * Inscription (compte client par défaut ; le rôle prestataire se fait via process métier / admin).
     */
    public function register(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (IdentifiantBloque::emailEstBloque((string) $value)) {
                        $fail('Cette adresse e-mail ne peut plus etre utilisee pour une inscription.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'max:255'],
            'prenom' => ['nullable', 'string', 'max:255'],
            'nom' => ['nullable', 'string', 'max:255'],
            'telephone' => [
                'nullable',
                'string',
                'max:32',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && IdentifiantBloque::telephoneEstBloque((string) $value)) {
                        $fail('Ce numero de telephone ne peut plus etre utilise pour une inscription.');
                    }
                },
            ],
        ]);

        $user = User::create([
            'name' => $donnees['name'],
            'email' => $donnees['email'],
            'password' => $donnees['password'],
            'role' => 'client',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        if ($request->filled('prenom') || $request->filled('nom') || $request->filled('telephone')) {
            Profil::create([
                'user_id' => $user->id,
                'prenom' => $donnees['prenom'] ?? null,
                'nom' => $donnees['nom'] ?? null,
                'telephone' => $donnees['telephone'] ?? null,
            ]);
        }

        $jeton = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'message' => 'Compte cree.',
            'token' => $jeton,
            'token_type' => 'Bearer',
            'user' => $this->formaterUtilisateur($user->load(['profil', 'prestataires'])),
        ], 201);
    }

    /**
     * Connexion : retourne un jeton Sanctum.
     */
    public function login(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $donnees['email'])->first();

        if (! $user || ! Hash::check($donnees['password'], $user->password)) {
            return response()->json([
                'message' => 'Identifiants invalides.',
            ], 422);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'message' => 'Votre compte n est pas encore active. Entrez le code OTP recu par email.',
                'otp_required' => true,
            ], 403);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Ce compte est desactive.',
            ], 403);
        }

        $user->tokens()->delete();

        $jeton = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'message' => 'Connecte.',
            'token' => $jeton,
            'token_type' => 'Bearer',
            'user' => $this->formaterUtilisateur($user->load(['profil', 'prestataires'])),
        ]);
    }

    /**
     * Déconnexion : révoque le jeton courant.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Deconnecte.']);
    }

    /**
     * Utilisateur connecté (profil + liens prestataire si présents).
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['profil', 'prestataires']);

        return response()->json($this->formaterUtilisateur($user));
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $user = User::query()->where('email', $payload['email'])->first();
        if (! $user) {
            return response()->json(['message' => 'Compte introuvable.'], 404);
        }

        $otp = EmailOtp::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            return response()->json(['message' => 'Aucun code OTP actif.'], 422);
        }
        if ($otp->attempts >= 5) {
            return response()->json(['message' => 'Nombre de tentatives depasse. Demandez un nouveau code.'], 422);
        }
        if ($otp->expires_at->isPast()) {
            return response()->json(['message' => 'Code OTP expire. Demandez un nouveau code.'], 422);
        }
        if (! Hash::check($payload['otp'], $otp->code_hash)) {
            $otp->increment('attempts');
            return response()->json(['message' => 'Code OTP invalide.'], 422);
        }

        $otp->update(['verified_at' => now()]);
        $user->forceFill([
            'email_verified_at' => now(),
            'status' => 'active',
        ])->save();

        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'message' => 'Compte active avec succes.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->formaterUtilisateur($user->load(['profil', 'prestataires'])),
        ]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $payload['email'])->first();
        if (! $user) {
            return response()->json(['message' => 'Compte introuvable.'], 404);
        }
        if ($user->email_verified_at !== null) {
            return response()->json(['message' => 'Ce compte est deja active.'], 422);
        }

        [$otpCode] = $this->creerOtp($user);
        $this->transactionalMailer->send($user->email, new OtpActivationMail($user, $otpCode));

        return response()->json(['message' => 'Nouveau code OTP envoye.']);
    }

    public function registerPrestataire(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (IdentifiantBloque::emailEstBloque((string) $value)) {
                        $fail('Cette adresse e-mail ne peut plus etre utilisee pour une inscription.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', 'string', 'min:8', 'max:255'],
            'nom' => ['required', 'string', 'max:255'],
            'raison_sociale' => ['required', 'string', 'max:255'],
            'numero_fiscal' => ['required', 'string', 'max:255'],
            'documents' => ['required', 'array', 'min:1', 'max:8'],
            'documents.*' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'documents_libelles' => ['nullable', 'array'],
            'documents_libelles.*' => ['nullable', 'string', 'max:255'],
        ]);

        $user = null;
        $prestataire = null;

        DB::transaction(function () use ($request, $payload, &$user, &$prestataire): void {
            $user = User::create([
                'name' => $payload['owner_name'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'role' => 'prestataire',
                'status' => 'inactive',
                'email_verified_at' => now(),
            ]);

            $prestataire = Prestataire::create([
                'nom' => $payload['nom'],
                'raison_sociale' => $payload['raison_sociale'],
                'numero_fiscal' => $payload['numero_fiscal'],
                'statut' => 'en_attente_validation',
                'valide_le' => null,
            ]);

            PrestataireMembre::create([
                'user_id' => $user->id,
                'prestataire_id' => $prestataire->id,
                'role_membre' => 'owner',
                'rejoint_le' => now(),
            ]);

            $files = $request->file('documents', []);
            $labels = $request->input('documents_libelles', []);
            foreach ($files as $index => $file) {
                $path = $file->store("prestataire-documents/{$prestataire->id}", 'local');
                PrestataireDocument::create([
                    'prestataire_id' => $prestataire->id,
                    'uploaded_by_user_id' => $user->id,
                    'libelle' => isset($labels[$index]) && trim((string) $labels[$index]) !== '' ? (string) $labels[$index] : null,
                    'nom_original' => $file->getClientOriginalName(),
                    'chemin_disque' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'taille_octets' => $file->getSize(),
                ]);
            }
        });

        $this->transactionalMailer->send($user->email, new PrestataireUnderReviewMail($prestataire));

        return response()->json([
            'message' => 'Demande prestataire recue. Notre equipe la verifiera sous 48h ouvrees.',
            'prestataire_id' => $prestataire->id,
            'status' => 'en_attente_validation',
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function formaterUtilisateur(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'email_verified_at' => $user->email_verified_at,
            'profil' => $user->profil,
            'prestataires' => $user->prestataires->map(fn ($p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'statut' => $p->statut,
                'pivot' => [
                    'role_membre' => $p->pivot->role_membre ?? null,
                ],
            ]),
        ];
    }

    /**
     * @return array{0:string}
     */
    private function creerOtp(User $user): array
    {
        $code = (string) random_int(100000, 999999);
        EmailOtp::query()->create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'verified_at' => null,
        ]);

        return [$code];
    }
}
