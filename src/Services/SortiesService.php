<?php

//////////////////// SORTIE SERVICE /////////////////////
///                                                   ///
/// Sert à gérer les différents traitement liés au    ///
/// ServiceController.                                ///
///                                                   ///
/////////////////////////////////////////////////////////

namespace App\Services;

use App\Entity\Inscriptions;
use App\Form\SortieFilterForm;
use App\Repository\SortieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;

class SortiesService extends AbstractController
{


    ////////////////////////////////////// constructeur
    public function __construct(
        private SortieRepository       $sortieRepository,
        private Security               $secu,
        private LieuRepository         $lieuRepository,
        private EntityManagerInterface $entityManager,
    )
    {}

    ////////////////////////////////////// les fonctions

    public function showOld()   //recherche des sorties archivées
    {
        $sorties = $this->sortieRepository->findBy(['etat' => 5]);
        return $sorties;
    }


    public function makeFilter($form)
    {
        /// I /// Recherche avec les filtres (ou pas)
        $baseFiltered = false;                                                          //défini si on est passé par le formulaire de filtres ou pas
        $check4 = false;

        $user = $this->secu->getUser();
        if ($user) {
            $user = $user->getId();
        }
        ///////////////////
        // Première requête, pour les filtres
        $queryBuilder = $this->sortieRepository->createQueryBuilder('s');
        ///////////////////
        // Deuxième requête, pour les sorties dont le user est l'auteur
        $queryBuilder2 = $this->sortieRepository->createQueryBuilder('ss');

        //Application des filtres pour ajouter aux requêtes
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if ($data['site']) {                                                        //recherche par liste des sites
                $queryBuilder->andWhere('s.site = :site')
                    ->setParameter('site', $data['site']);
            }

            if (!empty($data['name'])) {                                                //recherche par fragment de noms
                $queryBuilder->andWhere('s.nom LIKE :name')
                    ->setParameter('name', '%' . $data['name'] . '%');
            }

            if ($data['startDate']) {                                                   //par date de début
                $queryBuilder->andWhere('s.dateHeureDebut >= :startDate')
                    ->setParameter('startDate', $data['startDate']);
            }

            if ($data['endDate']) {                                                     //par date de fin
                $queryBuilder->andWhere('s.dateLimiteInscription <= :endDate')
                    ->setParameter('endDate', $data['endDate']);
            }

            if ($data['checkbox1']) {                                                   //par organisateur (soi-même)
                $queryBuilder->join('s.organisateur', 'p');
                $queryBuilder->andWhere('p.id = :userId')
                             ->setParameter('userId', $user);
            }

            if ($data['checkbox2']) {                                                   //par participation (soi-même)
                $queryBuilder->leftJoin('App\Entity\Inscriptions', 'i', 'WITH', 'i.sortie = s.id');
                $queryBuilder->andWhere('i.participant = :userId')
                             ->setParameter('userId', $user);
            }

            if ($data['checkbox3']) {                                                   //par non-participation (soi-même)
                $subQuery = $this->sortieRepository->createQueryBuilder('sq')
                    ->select('1')
                    ->from('App\Entity\Inscriptions', 'j')
                    ->where('j.sortie = s.id')
                    ->andWhere('j.participant = :userId')
                    ->getDQL();

                $queryBuilder->andWhere($queryBuilder->expr()->not($queryBuilder->expr()->exists($subQuery)))
                             ->setParameter('userId', $user);
            }

            if ($data['checkbox4']) {                                                   //pour consulter dans les archives
                $queryBuilder->andWhere($queryBuilder->expr()->eq('s.etat', 5));
                $check4 = true;
            }else{
                $queryBuilder->andWhere($queryBuilder->expr()->in('s.etat', [2, 3, 4, 6]));

                $queryBuilder2 = $this->sortieRepository->createQueryBuilder('ss')
                    ->andWhere('ss.organisateur = :userId')
                    ->setParameter('userId', $user)
                    ->andWhere($queryBuilder->expr()->in('ss.etat', 1));
            }
            $baseFiltered = true;
        }

        // si on a activé aucun filtre (au premier chargement par exemple) on joue le filtrage par défaut en fonction des états
        if (!$baseFiltered) {
            $queryBuilder->andWhere($queryBuilder->expr()->in('s.etat', [2, 3, 4, 6]));

            $queryBuilder2->andWhere('ss.organisateur = :userId')
                          ->setParameter('userId', $user)
                          ->andWhere($queryBuilder->expr()->in('ss.etat', 1));

            //résultat des requêtes
            $Query1 = $queryBuilder->getQuery()->getResult();
            $Query2 = $queryBuilder2->getQuery()->getResult();

            //combinaison des deux requêtes
            $allRequests = array_merge($Query1, $Query2);
            return $allRequests;

        }else if ($baseFiltered && $check4){
            //résultat des requêtes
            $Query1 = $queryBuilder->getQuery()->getResult();
            return $Query1;

        }else{
            //résultat des requêtes
            $Query1 = $queryBuilder->getQuery()->getResult();
            $Query2 = $queryBuilder2->getQuery()->getResult();

            //combinaison des deux requêtes
            $allRequests = array_merge($Query1, $Query2);
            return $allRequests;
        }
    }
    public function changeState($sortieId, $state){
        $sortie = $this->entityManager->getRepository(Sortie::class)->findOneBy([ 'id' => $sortieId ]);
        $etat = $this->entityManager->getRepository(Etat::class)->findOneBy([ 'id' => $state ]);
        if (!$sortie) {
            throw new \Exception("La sortie n'existe pas");
        }
        $sortie->setEtat($etat);
        $this->entityManager->persist($sortie);         // inscription dans la base
        $this->entityManager->flush();                  // flush
    }
    public function delete($sortieId){
        $sortie = $this->entityManager->getRepository(Sortie::class)->findOneBy([ 'id' => $sortieId ]);
        if (!$sortie) {
            throw new \Exception("La sortie n'existe pas");
        }

        $inscriptions = $this->entityManager->getRepository(Inscriptions::class)->findBy([ 'sortie' => $sortieId ]);
        foreach ($inscriptions as $inscription) {
            $this->entityManager->remove($inscription);
        }

        $this->entityManager->remove($sortie);          // inscription dans la base
        $this->entityManager->flush();                  // flush
    }

    public function creerSortie(Request $request): Response
    {
        $sortie = new Sortie();
        $organisateur = $this->secu->getUser();
        $form = $this->createForm(CreaSortieFormType::class, $sortie);

        return $this->creerOuModifierUneSortie($request, $form, $sortie, $organisateur, false);

    }

    public function modifierUneSortie(Request $request, string $id): Response
    {
        $sortie = $this->entityManager->getRepository(Sortie::class)->find($id);
        $organisateur = $sortie->getOrganisateur();
        $form = $this->createForm(CreaSortieFormType::class, $sortie, ['is_edit' => true]);

        return $this->creerOuModifierUneSortie($request, $form, $sortie, $organisateur, true);
    }

    ///////////////////////////////// Fonctions privées
    /**
     * Fonction privée qui évite la répétition et qui
     * transforme les données de lieux en JSON pour le remplissage
     * dynamique des champs via Javascript
     */
    private function getLieuxData(): bool|string
    {
        $lieux = $this->lieuRepository->findAll();
        $lieuxData = [];
        foreach ($lieux as $lieu) {
            $lieuxData[] = [
                'id' => $lieu->getId(),
                'ville' => $lieu->getVille(),
                'rue' => $lieu->getRue(),
                'codePostal' => $lieu->getCodePostal()
            ];
        }
        return $lieuxData = json_encode($lieuxData);
    }

    /**
     * Fonction privée qui regroupe les traits communs de créer ou modifier une Sortie
     */
    private function creerOuModifierUneSortie(Request $request, $form, $sortie, $organisateur, $isEdit): Response
    {
        $lieux = $this->getLieuxData();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('enregistrer')->isClicked()) {
                $etat = $this->entityManager->getRepository(Etat::class)->findOneBy(['id' => '1']);
            } elseif ($form->get('publier')->isClicked()) {
                $etat = $this->entityManager->getRepository(Etat::class)->findOneBy(['id' => '2']);
            }

            if (isset($etat)) {
                $sortie->setEtat($etat);
            }

            $sortie->setOrganisateur($organisateur)
                ->setSite($organisateur->getSite());

            $this->entityManager->persist($sortie);
            $this->entityManager->flush();

            $flashMessage = $sortie->getEtat()->getId() == 1
                ? 'Sortie ' . $sortie->getNom() . ' correctement enregistrée'
                : 'Sortie ' . $sortie->getNom() . ' correctement publiée';

            $this->addFlash('success', $flashMessage);

            return $this->redirectToRoute('sortie_main');
        }

        return $this->render('sortie/formulaireSortie.html.twig', [
            'sortieForm' => $form->createView(),
            'is_edit' => $isEdit,
            'organisateur' => $organisateur,
            'lieux' => $lieux,
            'sortie' => $sortie,
        ]);
    }
}