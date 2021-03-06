<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\Document;

use Pimcore\Controller\Traits\ElementEditLockHelperTrait;
use Pimcore\Event\Admin\ElementAdminStyleEvent;
use Pimcore\Event\AdminEvents;
use Pimcore\Model\Document;
use Pimcore\Model\Element;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/hardlink")
 */
class HardlinkController extends DocumentControllerBase
{
    use ElementEditLockHelperTrait;

    /**
     * @Route("/get-data-by-id", methods={"GET"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getDataByIdAction(Request $request)
    {
        $link = Document\Hardlink::getById($request->get('id'));

        if (!$link) {
            throw $this->createNotFoundException('Hardlink not found');
        }

        // check for lock
        if ($link->isAllowed('save') || $link->isAllowed('publish') || $link->isAllowed('unpublish') || $link->isAllowed('delete')) {
            if (Element\Editlock::isLocked($request->get('id'), 'document')) {
                return $this->getEditLockResponse($request->get('id'), 'document');
            }
            Element\Editlock::lock($request->get('id'), 'document');
        }

        $link = clone $link;

        $link->idPath = Element\Service::getIdPath($link);
        $link->setUserPermissions($link->getUserPermissions());
        $link->setLocked($link->isLocked());
        $link->setParent(null);

        if ($link->getSourceDocument()) {
            $link->sourcePath = $link->getSourceDocument()->getRealFullPath();
        }

        $this->addTranslationsData($link);
        $this->minimizeProperties($link);
        $link->getScheduledTasks();

        //Hook for modifying return value - e.g. for changing permissions based on object data
        //data need to wrapped into a container in order to pass parameter to event listeners by reference so that they can change the values
        $data = $link->getObjectVars();

        $data['php'] = [
            'classes' => array_merge([get_class($link)], array_values(class_parents($link))),
            'interfaces' => array_values(class_implements($link))
        ];

        $this->addAdminStyle($link, ElementAdminStyleEvent::CONTEXT_EDITOR, $data);

        $event = new GenericEvent($this, [
            'data' => $data,
            'document' => $link
        ]);
        \Pimcore::getEventDispatcher()->dispatch(AdminEvents::DOCUMENT_GET_PRE_SEND_DATA, $event);
        $data = $event->getArgument('data');
        $data['versionDate'] = $link->getModificationDate();

        if ($link->isAllowed('view')) {
            return $this->adminJson($data);
        }

        throw $this->createAccessDeniedHttpException();
    }

    /**
     * @Route("/save", methods={"POST", "PUT"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function saveAction(Request $request)
    {
        $link = Document\Hardlink::getById($request->get('id'));

        if (!$link) {
            throw $this->createNotFoundException('Hardlink not found');
        }

        $this->setValuesToDocument($request, $link);

        $link->setModificationDate(time());
        $link->setUserModification($this->getAdminUser()->getId());

        if ($request->get('task') == 'unpublish') {
            $link->setPublished(false);
        }
        if ($request->get('task') == 'publish') {
            $link->setPublished(true);
        }

        // only save when publish or unpublish
        if (($request->get('task') == 'publish' && $link->isAllowed('publish')) || ($request->get('task') == 'unpublish' && $link->isAllowed('unpublish'))) {
            $link->save();

            $this->addAdminStyle($link, ElementAdminStyleEvent::CONTEXT_EDITOR, $treeData);

            return $this->adminJson([
                'success' => true,
                 'data' => [
                     'versionDate' => $link->getModificationDate(),
                     'versionCount' => $link->getVersionCount()
                 ],
                'treeData' => $treeData
            ]);
        } else {
            throw $this->createAccessDeniedHttpException();
        }
    }

    /**
     * @param Request $request
     * @param Document\Hardlink $link
     */
    protected function setValuesToDocument(Request $request, Document $link)
    {
        // data
        if ($request->get('data')) {
            $data = $this->decodeJson($request->get('data'));

            $sourceId = null;
            if ($sourceDocument = Document::getByPath($data['sourcePath'])) {
                $sourceId = $sourceDocument->getId();
            }
            $link->setSourceId($sourceId);
            $link->setValues($data);
        }

        $this->addPropertiesToDocument($request, $link);
        $this->applySchedulerDataToElement($request, $link);
    }
}
