<?php

namespace Flame\Exception;

use Flame\Event\ModuleEvent;

class ModuleEventException extends \Exception
{
    public function __construct(protected ModuleEvent $event)
    {
        parent::__construct($this->event->getAbortReason());
    }

    public function getEvent(): ModuleEvent
    {
        return $this->event;
    }
}