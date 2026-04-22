pipeline {
    agent any

    environment {
        APP_URL = 'https://pure-clarity-production-9d74.up.railway.app'
    }

    triggers {
        githubPush()
    }

    stages {
        stage('Clone') {
            steps {
                git credentialsId: 'github-token',
                    url: 'https://github.com/Small-Danger/all-event-backend.git',
                    branch: 'main'
            }
        }
        stage('Installation dependances') {
            steps {
                sh 'composer install --no-interaction --prefer-dist'
            }
        }
        stage('Tests unitaires') {
            steps {
                sh 'php artisan test || true'
            }
        }
        stage('SAST - Audit Composer') {
            steps {
                sh 'composer audit || true'
            }
        }
        stage('Secrets - Gitleaks') {
            steps {
                sh 'gitleaks detect -s . -v --log-opts="HEAD~1..HEAD" || true'
            }
        }
        stage('Build Docker') {
            steps {
                sh 'docker build -t allevent-backend:latest .'
            }
        }
        stage('Scan Trivy') {
            steps {
                sh 'trivy image --exit-code 0 --severity HIGH,CRITICAL allevent-backend:latest || true'
            }
        }
        stage('DAST - ZAP') {
            steps {
                sh 'zaproxy -cmd -quickurl ${APP_URL} -quickprogress || true'
            }
        }
    }

    post {
        success {
            echo 'Pipeline DevSecOps Backend reussi !'
        }
        failure {
            echo 'Pipeline echoue - verifier les logs'
        }
    }
}
