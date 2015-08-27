<?php
/**
 * Created by PhpStorm.
 * User: matt
 * Date: 8/27/15
 * Time: 4:00 PM
 */

namespace PAMI\Client\Impl;


use PAMI\Message\Action\ActionMessage;
use React\Promise\Deferred;

class Action
{
    private $actionMessage;
    private $responseMessage;
    private $deferred;

    /**
     * Action constructor.
     * @param $actionMessage
     */
    public function __construct($actionMessage)
    {
        $this->actionMessage = $actionMessage;

        $this->deferred = new Deferred();
    }


    /**
     * @return ActionMessage
     */
    public function getActionMessage()
    {
        return $this->actionMessage;
    }

    /**
     * @param mixed $actionMessage
     */
    public function setActionMessage($actionMessage)
    {
        $this->actionMessage = $actionMessage;
    }

    /**
     * @return mixed
     */
    public function getResponseMessage()
    {
        return $this->responseMessage;
    }

    /**
     * @param mixed $responseMessage
     */
    public function setResponseMessage($responseMessage)
    {
        $this->responseMessage = $responseMessage;
    }

    /**
     * @return Deferred
     */
    public function getDeferred()
    {
        return $this->deferred;
    }

    /**
     * @param mixed $deferred
     */
    public function setDeferred($deferred)
    {
        $this->deferred = $deferred;
    }


}