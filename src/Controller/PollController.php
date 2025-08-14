<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Attribute\Route;
use App\Form\PollFormType;
use App\Entity\PollOption;
use App\Entity\Poll;

final class PollController extends AbstractController
{
    #[Route('/poll/create', name: 'app_poll_create')]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
    $form = $this->createForm(PollFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Poll $poll */
            $poll = $form->getData();

            $options = $request->request->all('options');

            foreach ($options as $label) {
                $option = new PollOption();
                $option->setLabel($label);
                $option->setPoll($poll);
                $em->persist($option);
            }
            $poll->setCreatedAt(new \DateTimeImmutable());
            $poll->setClosedAt((new \DateTimeImmutable())->modify('+30 days'));
            $em->persist($poll);
            $em->flush();

            $this->addFlash('success', 'Ankieta została utworzona!');
            return $this->redirectToRoute('app_poll_info', ['id' => $poll->getId()]);
        }

        return $this->render('poll/create.html.twig', [
            'pollForm' => $form
        ]);

    }

    #[Route('/poll/list', name: 'app_poll_list')]
    public function list(EntityManagerInterface $em): Response
    {
        $polls = $em->getRepository(Poll::class)->findAll();

        $pollsWithVotes = [];
        foreach ($polls as $poll) {
            $totalVotes = 0;
            foreach ($poll->getOptions() as $option) {
                $totalVotes += $option->getVotes();
            }
            $pollsWithVotes[] = [
                'poll' => $poll,
                'totalVotes' => $totalVotes
            ];
        }

        return $this->render('poll/list.html.twig', [
            'pollsWithVotes' => $pollsWithVotes,
        ]);
    }

    
    #[Route('/poll/info/{id}', name: 'app_poll_info', methods: ['GET'])]
    public function info(Request $request, EntityManagerInterface $em, int $id): Response
    {
        $poll = $em->getRepository(Poll::class)->find($id);
        if (!$poll) {
            throw $this->createNotFoundException('Ankieta nie istnieje.');
        }

        // Dane do wykresu
        $labels = [];
        $data   = [];
        foreach ($poll->getOptions() as $opt) {
            $labels[] = $opt->getLabel();
            $data[]   = $opt->getVotes();
        }

        // Czy można dziś głosować? (cookie + zamknięcie ankiety)
        $canVote = $this->canVote($request, $poll);

        return $this->render('poll/info.html.twig', [
            'poll'    => $poll,
            'labels'  => $labels,
            'data'    => $data,
            'canVote' => $canVote,
        ]);
    }

    #[Route('/poll/{id}/vote', name: 'app_poll_vote', methods: ['POST'])]
    public function vote(Request $request, EntityManagerInterface $em, int $id): Response
    {
        $poll = $em->getRepository(Poll::class)->find($id);
        if (!$poll) {
            throw $this->createNotFoundException('Ankieta nie istnieje.');
        }

        // CSRF
        if (!$this->isCsrfTokenValid('vote'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Nieprawidłowy token CSRF.');
            return $this->redirectToRoute('app_poll_info', ['id' => $id]);
        }

        // Limit: 1 głos dziennie
        if (!$this->canVote($request, $poll)) {
            $this->addFlash('error', 'Dziś już głosowałeś albo ankieta jest zamknięta.');
            return $this->redirectToRoute('app_poll_info', ['id' => $id]);
        }

        // Wybrana opcja
        $optionId = $request->request->get('option');
        /** @var PollOption|null $option */
        $option = $em->getRepository(PollOption::class)->find($optionId);

        // Walidacja: opcja musi należeć do tej ankiety
        if (!$option || $option->getPoll()->getId() !== $poll->getId()) {
            $this->addFlash('error', 'Wybrano nieprawidłową opcję.');
            return $this->redirectToRoute('app_poll_info', ['id' => $id]);
        }

        // Zlicz głos
        $option->setVotes($option->getVotes() + 1);
        $em->flush();

        // Ustaw cookie "zagłosował dziś" do północy czasu PL
        $tz = new \DateTimeZone('Europe/Warsaw');
        $tomorrowMidnight = (new \DateTimeImmutable('tomorrow', $tz))->setTime(0, 0);
        $cookieName = $this->getVoteCookieName($poll, $tz);

        $response = $this->redirectToRoute('app_poll_info', ['id' => $id]);
        $response->headers->setCookie(
            Cookie::create(
                name: $cookieName,
                value: '1',
                expire: $tomorrowMidnight,
                path: '/',
                secure: false,   // w prod ustaw true jeśli HTTPS
                httpOnly: false, // nie musi być httpOnly
                sameSite: 'lax'
            )
        );

        $this->addFlash('success', 'Głos zapisany. Dzięki!');
        return $response;
    }

    private function canVote(Request $request, Poll $poll): bool
    {
        // Zamknięta ankieta?
        $tz = new \DateTimeZone('Europe/Warsaw');
        $now = new \DateTimeImmutable('now', $tz);
        if ($poll->getClosedAt() && $poll->getClosedAt() <= $now) {
            return false;
        }

        // Cookie „dzisiaj już głosował”
        $cookieName = $this->getVoteCookieName($poll, $tz);
        return !$request->cookies->has($cookieName);
    }

    private function getVoteCookieName(Poll $poll, \DateTimeZone $tz): string
    {
        $today = (new \DateTimeImmutable('today', $tz))->format('Ymd');
        return sprintf('voted_%d_%s', $poll->getId(), $today);
    }
}
