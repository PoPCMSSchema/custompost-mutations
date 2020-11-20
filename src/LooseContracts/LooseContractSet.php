<?php

declare(strict_types=1);

namespace PoPSchema\CustomPostMutations\LooseContracts;

use PoP\LooseContracts\AbstractLooseContractSet;

class LooseContractSet extends AbstractLooseContractSet
{
    public const NAME_EDIT_POSTS_CAPABILITY = 'popcms:capability:editPosts';
    /**
     * @return string[]
     */
    public function getRequiredNames(): array
    {
        return [
            self::NAME_EDIT_POSTS_CAPABILITY,
        ];
    }
}
