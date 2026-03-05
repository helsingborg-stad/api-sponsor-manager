<?php

namespace ApiSponsorManager\Helper\HooksRegistrar;

interface HooksRegistrarInterface
{
    public function register(Hookable $object): HooksRegistrarInterface;
}
