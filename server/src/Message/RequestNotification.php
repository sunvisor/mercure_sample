<?php
/**
 * User: sunvisor
 * Date: 2020/03/18
 *
 * Copyright (C) Sunvisor Lab. 2020.
 */
namespace App\Message;

/**
 * Class RequestNotification
 *
 * 通知クラス
 *
 * @package App\Message
 */
class RequestNotification
{
    /**
     * @var int
     */
    private $type;
    /**
     * @var string
     */
    private $messageId;
    /**
     * @var string
     */
    private $topic;

    /**
     * RequestNotification constructor.
     * @param int    $type
     * @param string $url
     */
    public function __construct(int $type, string $url)
    {
        $this->type = $type;
        // メッセージID を生成
        $this->messageId = uniqid();
        $this->topic = $url . $this->messageId;
    }

    /**
     * @return string
     */
    public function getMessageId()
    {
        return $this->messageId;
    }

    /**
     * @return int
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getTopic()
    {
        return $this->topic;
    }
}