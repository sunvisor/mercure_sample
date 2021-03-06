<?php
/** @noinspection PhpUnused */

namespace App\Controller;

use App\Message\RequestNotification;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class AsyncController extends AbstractController
{
    /**
     * 非同期のリクエスト
     *
     * @Route("/request", name="async_request")
     * @param Request             $request
     * @param MessageBusInterface $bus
     * @return JsonResponse
     */
    public function requestAction(Request $request, MessageBusInterface $bus)
    {
        $type = $request->request->get('type');
        $url = "{$request->getSchemeAndHttpHost()}/read/";
        // 通知オブジェクトを作成
        $notification = new RequestNotification($type, $url);
        // bus に処理を依頼
        $bus->dispatch($notification);
        // メッセージID を返す
        return new JsonResponse([
            'messageId' => $notification->getMessageId(),
            'topic'     => $notification->getTopic(),
            'success'   => true
        ]);
    }

    /**
     * 処理の状態を確認
     *
     * @Route("/read/{id}", name="async_read")
     * @param $id
     * @return JsonResponse
     */
    public function readAction($id)
    {
        $fileName = __DIR__ . "/../../var/result{$id}.txt";
        if (!file_exists($fileName)) {
            throw $this->createNotFoundException('cannot found data');
        }
        // 非同期でつくられるファイルの内容を取得して返す
        $content = file_get_contents($fileName);
        $result = json_decode($content);
        return new JsonResponse($result);
    }
}
