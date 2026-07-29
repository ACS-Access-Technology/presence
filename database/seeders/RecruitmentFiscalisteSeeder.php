<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Filiale;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use Illuminate\Database\Seeder;

/**
 * Contenu du test Fiscaliste Groupe (V1 : Partie B seule, 18 transactions).
 * Source : 04_Classeur_Maitre_Confidentiel.xlsx (onglet Corrigé CONFIDENTIEL),
 * croisé avec l'onglet Transactions du classeur candidat pour le contexte
 * affiché. `reponse_attendue` ne doit JAMAIS être chargé côté public.
 */
class RecruitmentFiscalisteSeeder extends Seeder
{
    public function run(): void
    {
        $test = RecruitmentTest::firstOrCreate(
            ['slug' => 'fiscaliste-groupe'],
            [
                'filiale_id' => Filiale::defaultId(),
                'titre' => 'Fiscaliste Groupe',
                'duree_minutes' => 45,
                'instructions' => 'Cas KÔRÔ HOLDING — juin N. Pour chacune des 18 transactions, indiquez le classement, l\'ajustement de TVA (par rapport au taux d\'épreuve de 18 %) et la retenue attendue.',
                'actif' => true,
            ],
        );

        if ($test->questions()->exists()) {
            return;
        }

        foreach (self::transactions() as $ordre => $row) {
            RecruitmentQuestion::create([
                'recruitment_test_id' => $test->id,
                'ordre' => $ordre + 1,
                'contexte' => [
                    'id' => $row[0],
                    'entite' => $row[1],
                    'date' => $row[2],
                    'piece' => $row[3],
                    'tiers' => $row[4],
                    'nature' => $row[5],
                    'montant_ht' => $row[6],
                    'tva_comptabilisee' => $row[7],
                    'retenue_operee' => $row[8],
                    'informations' => $row[9],
                ],
                'reponse_attendue' => [
                    'classement' => $row[10],
                    'ajustement_tva' => $row[11],
                    'retenue_attendue' => $row[12],
                    'explication' => $row[13],
                ],
                'tolerance' => 0,
            ]);
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int, 9: string, 10: string, 11: int, 12: int, 13: string}>
     */
    private static function transactions(): array
    {
        return [
            ['T001', 'Kôrô Technologies', '2026-06-03', 'FV-601', 'Client Lagune', 'Vente de licence', 12500000, 2250000, 0, 'Contrat, facture et PV disponibles', 'Conforme', 0, 0, 'TVA collectée à rapprocher'],
            ['T002', 'Kôrô BTP', '2026-06-04', 'FA-882', 'Métal Ivoire', 'Achat matériaux', 8400000, 1680000, 0, 'Facture : HT 8 400 000 ; TVA affichée 1 680 000', 'Majeur', -168000, 0, 'TVA admise 1 512 000 ; excès 168 000'],
            ['T003', 'Kôrô Technologies', '2026-06-05', 'FA-214', 'Conseil Plus SARL', 'Mission organisation', 3200000, 576000, 0, 'Facture et contrat ; preuve de service absente', 'Modéré', -576000, 0, "Suspendre jusqu'à preuve de service"],
            ['T004', 'Kôrô Holding', '2026-06-06', 'FA-017', 'Auto Prestige', 'Entretien véhicule dirigeant', 1750000, 315000, 0, 'Note interne : usage personnel majoritaire', 'Majeur', -315000, 0, 'Dépense personnelle'],
            ['T005', 'Kôrô Technologies', '2026-06-08', 'HC-095', 'M. Kadio', 'Conseil cybersécurité', 4600000, 0, 230000, 'Consultant résident personne physique ; prestation validée', 'Majeur', 0, 345000, 'R-1 7,5 % ; insuffisance 115 000'],
            ['T006', 'Kôrô Technologies', '2026-06-09', 'INV-74', 'Cloud Meridian Ltd', 'Hébergement cloud', 9800000, 0, 0, 'Prestataire non-résident ; aucune preuve conventionnelle', 'Critique', 0, 1960000, 'R-2 20 %, réserve convention'],
            ['T007', 'Kôrô BTP', '2026-06-10', 'AV-033', 'Client Pont Sud', 'Avoir sur travaux', -2100000, -378000, 0, 'Avoir reçu mais non intégré à la déclaration préparée', 'Modéré', -378000, 0, "Intégrer l'avoir"],
            ['T008', 'Kôrô BTP', '2026-06-11', 'FA-455', 'Béton Express', 'Achat béton', 6700000, 1206000, 0, "Même numéro, montant et fournisseur que l'écriture du 30 mai", 'Majeur', -1206000, 0, 'Doublon probable'],
            ['T009', 'Kôrô Technologies', '2026-06-12', 'FA-991', 'Data Equip', 'Serveur', 15000000, 2700000, 0, 'Facture disponible ; PV de réception et mise en service absents', 'Modéré', -2700000, 0, 'Suspension documentaire'],
            ['T010', 'Kôrô Immobilier', '2026-06-13', 'BAIL-12', 'Mme Assi', 'Loyer bureaux', 6000000, 0, 0, 'Bailleur personne physique ; loyer payé intégralement', 'Majeur', 0, 900000, 'R-3 15 %'],
            ['T011', 'Kôrô Holding', '2026-06-14', 'FA-119', 'Papeterie Centre', 'Fournitures', 450000, 81000, 0, 'Facture régulière, livraison et usage professionnel', 'Conforme', 0, 0, 'Déduction admise'],
            ['T012', 'Kôrô BTP', '2026-06-15', 'FA-620', 'Élec Service', 'Maintenance', 2300000, 414000, 0, 'Facture sans NIF fournisseur', 'Majeur', -414000, 0, 'NIF absent'],
            ['T013', 'Kôrô Holding', '2026-06-16', 'AC-041', 'Stratégie Afrique', 'Acompte mission', 12000000, 2160000, 0, 'Paiement effectué ; contrat non signé ; service non commencé', 'Critique', -2160000, 0, 'Service non commencé, contrat non signé'],
            ['T014', 'Kôrô Immobilier', '2026-06-18', 'FA-308', 'Fournisseur Zénith', 'Opération signalée exonérée', 5100000, 918000, 0, 'Mention « exonéré » sur la pièce mais TVA comptabilisée', 'Majeur', -918000, 0, "TVA non admise dans l'épreuve"],
            ['T015', 'Kôrô Technologies', '2026-06-20', 'FA-711', 'Audit Systems', 'Audit technique', 3900000, 702000, 0, 'Facture datée mai, reçue et comptabilisée en juin', 'Modéré', 0, 0, 'Vérifier période/exigibilité'],
            ['T016', 'Kôrô BTP', '2026-06-22', 'FV-633', 'Client Route Est', 'Situation de travaux', 22000000, 3960000, 0, 'Facture, attachement et validation disponibles', 'Conforme', 0, 0, 'TVA collectée'],
            ['T017', 'Kôrô Technologies', '2026-06-23', 'HC-101', 'Mme Naya', 'Formation', 2800000, 0, 210000, 'Retenue opérée correctement ; non reversée à la date du 25 juillet', 'Critique', 0, 210000, 'Retenue exacte mais non reversée'],
            ['T018', 'Kôrô BTP', '2026-06-25', 'FA-970', 'Géo Expert', 'Étude de sol', 7200000, 1296000, 0, 'Facture libellée à Kôrô Immobilier, charge enregistrée chez Kôrô BTP', 'Modéré', -1296000, 0, 'Mauvaise entité facturée'],
        ];
    }
}
