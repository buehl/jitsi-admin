<?php

namespace App\Controller;

use App\Entity\Rooms;
use App\Entity\Server;
use App\Entity\User;
use App\Form\Type\JoinViewType;
use App\Service\PexelService;
use App\Service\RoomService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class JoinController extends AbstractController
{
    private $parameterBag;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $this->parameterBag = $parameterBag;
    }

    /**
     * @Route("/join/{slug}", name="join_index")
     * @Route("/join", name="join_index_no_slug")
     */
    public function index($slug = null, PexelService $pexelService, Request $request, TranslatorInterface $translator, RoomService $roomService, HttpClientInterface $httpClient)
    {
        $data = array();
        $server = $this->getDoctrine()->getRepository(Server::class)->findOneBy(['slug' => $slug]);
        // dataStr wird mit den Daten uid und email encoded übertragen. Diese werden daraufhin als Vorgaben in das Formular eingebaut
        $dataStr = $request->get('data');
        $snack = $request->get('snack');
        $dataAll = base64_decode($dataStr);
        $data = array();


        parse_str($dataAll, $data);
        if ($request->cookies->get('name')) {
            $data['name'] = $request->cookies->get('name');
        }
        if (isset($data['email']) && isset($data['uid'])) {
            $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['uid' => $data['uid']]);
            $user = $this->getDoctrine()->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            //If the room ID is correct set and the room exists
            if ($this->testRoomPermissions($room, $user)) {
                return $this->redirectToRoute('room_join', ['room' => $room->getId(), 't' => 'b']);
            }
        } else {
            $snack = $translator->trans('Zugangsdaten in das Formular eingeben');
        }

        if ($this->parameterBag->get('laF_onlyRegisteredParticipents') == 1) {
            return $this->redirectToRoute('dashboard');
        }

        $form = $this->createForm(JoinViewType::class, $data);
        $form->handleRequest($request);
        $errors = array();
        if ($form->isSubmitted() && $form->isValid()) {
            $search = $form->getData();
            $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['uid' => $search['uid']]);
            $user = $this->getDoctrine()->getRepository(User::class)->findOneBy(['email' => $search['email']]);

            if (count($errors) == 0 && $room && $user && in_array($user, $room->getUser()->toarray())) {
                if ($this->testRoomPermissions($room, $user)) {
                    return $this->redirectToRoute('room_join', ['room' => $room->getId(), 't' => 'b']);
                }
                $url = $roomService->join($room, $user, 'b', $search['name']);
                $res = $this->redirect($url);
                $res->headers->setCookie(new Cookie('name', $search['name'], (new \DateTime())->modify('+365 days')));
                return $res;
            }
            $snack = $translator->trans('Konferenz nicht gefunden. Zugangsdaten erneut eingeben');
        }
        $image = $pexelService ->getImageFromPexels();

        return $this->render('join/index.html.twig', [
            'form' => $form->createView(),
            'snack' => $snack,
            'server' => $server,
            'image' => $image,

        ]);
    }

    function testRoomPermissions(?Rooms $room, ?User $user)
    {
        if ($room) {
            if (
                $this->parameterBag->get('laF_onlyRegisteredParticipents') == 1 ||//only registered USers globaly set
                $room->getOnlyRegisteredUsers() || // only registered users for this room are alloed
                ($user && $user->getKeycloakId() !== null)//the users was already loged in, so he needs to sign in again
            ) {
                return true;
            }
            return false;
        }
    }
}
