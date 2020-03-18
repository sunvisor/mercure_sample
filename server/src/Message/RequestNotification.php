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
     * RequestNotification constructor.
     * @param int    $type
     */
    public function __construct(int $type)
    {
        $this->type = $type;
        // メッセージID を生成
        $this->messageId = uniqid();
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
}