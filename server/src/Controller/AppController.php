<?php
/** @noinspection PhpUnused */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class AppController extends AbstractController
{
    /**
     * @Route("/", name="index")
     */
    public function index()
    {
        return $this->redirectToRoute('app');
    }

    /**
     * アプリケーション
     *
     * @Route("app/", name="app")
     */
    public function appIndex()
    {
        return $this->render('app/index.html.twig', [
            'params' => []
        ]);
    }

}
