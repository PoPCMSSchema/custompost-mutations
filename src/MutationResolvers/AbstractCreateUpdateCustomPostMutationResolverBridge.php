<?php

declare(strict_types=1);

namespace PoPSchema\CustomPostMutations\MutationResolvers;

use PoP\Hooks\Facades\HooksAPIFacade;
use PoPSchema\CustomPosts\Types\Status;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoPSchema\CustomPosts\Facades\CustomPostTypeAPIFacade;
use PoP\ComponentModel\ModuleProcessors\DataloadingConstants;
use PoP\ComponentModel\MutationResolvers\AbstractCRUDComponentMutationResolverBridge;

abstract class AbstractCreateUpdateCustomPostMutationResolverBridge extends AbstractCRUDComponentMutationResolverBridge
{
    /**
     * @param mixed $result_id Maybe an int, maybe a string
     */
    public function getSuccessString($result_id): ?string
    {
        $customPostTypeAPI = CustomPostTypeAPIFacade::getInstance();
        $status = $customPostTypeAPI->getStatus($result_id);
        if ($status == Status::PUBLISHED) {
            $success_string = sprintf(
                TranslationAPIFacade::getInstance()->__('<a href="%s" %s>Click here to view it</a>.', 'pop-application'),
                $customPostTypeAPI->getPermalink($result_id),
                getReloadurlLinkattrs()
            );
        } elseif ($status == Status::DRAFT) {
            $success_string = TranslationAPIFacade::getInstance()->__('The status is still “Draft”, so it won\'t be online.', 'pop-application');
        } elseif ($status == Status::PENDING) {
            $success_string = TranslationAPIFacade::getInstance()->__('Now waiting for approval from the admins.', 'pop-application');
        }

        return HooksAPIFacade::getInstance()->applyFilters('gd-createupdate-post:execute:successstring', $success_string, $result_id, $status);
    }

    /**
     * @param mixed $result_id Maybe an int, maybe a string
     */
    protected function modifyDataProperties(array &$data_properties, $result_id): void
    {
        parent::modifyDataProperties($data_properties, $result_id);

        $data_properties[DataloadingConstants::QUERYARGS]['custom-post-status'] = [
            Status::PUBLISHED,
            Status::PENDING,
            Status::DRAFT,
        ];
    }
}

