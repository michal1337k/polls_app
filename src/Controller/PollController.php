<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Form\PollFormType;
use App\Entity\PollOption;
use App\Entity\Poll;

final class PollController extends AbstractController
{
    #[Route('/poll/create', name: 'app_poll_create')]
    public function index(Request $request, EntityManagerInterface $em): Response
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
            return $this->redirectToRoute('app_poll_create');
        }

        return $this->render('poll/create.html.twig', [
            'pollForm' => $form
        ]);

    }
}
