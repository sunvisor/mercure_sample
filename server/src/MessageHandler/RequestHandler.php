<?php
/**
 * User: sunvisor
 * Date: 2020/03/18
 *
 * Copyright (C) Sunvisor Lab. 2020.
 */

namespace App\MessageHandler;


use App\Message\RequestNotification;
use Symfony\Component\Mercure\PublisherInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Class RequestHandler
 *
 * メッセージを処理するクラス
 *
 * @package App\MessageHandler
 */
class RequestHandler implements MessageHandlerInterface
{
    /**
     * @var PublisherInterface
     */
    private $publisher;

    /**
     * RequestHandler constructor.
     * @param PublisherInterface $publisher
     */
    public function __construct(PublisherInterface $publisher)
    {
        // push 通知を行う publisher を注入
        $this->publisher = $publisher;
    }

    public function __invoke(RequestNotification $message)
    {
        $data = [
            'messageId' => $message->getMessageId(),
            'type' => $message->getType(),
            'state' => 'in_progress'
        ];
        $id = $message->getMessageId();
        // 作業中のステータスでファイルを書く
        $this->writeContents($id, $data);
        // 処理に時間家がかるのを演出
        sleep(10);
        // ステータスを完了に変更
        $data['state'] = 'done';
        $this->writeContents($id, $data);
        // push 通知を送る
        ($this->publisher)(new Update($id, json_encode($data)));
    }

    /**
     * @param string $id
     * @param array  $data
     */
    private function writeContents(string $id, array $data): void
    {
        $fileName = __DIR__ . "/../../var/result{$id}.txt";
        $contents = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($fileName, $contents);
    }
}