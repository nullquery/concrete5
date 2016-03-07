<?php
namespace Concrete\Controller\Panel\Page;

use Concrete\Controller\Backend\UserInterface\Page as BackendInterfacePageController;
use Concrete\Core\Form\Service\Widget\DateTime;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\User\User;
use Permissions;
use CollectionVersion;
use Loader;
use Page;
use Concrete\Core\Workflow\Request\ApprovePageRequest as ApprovePagePageWorkflowRequest;
use PageEditResponse;

class CheckIn extends BackendInterfacePageController
{
    protected $viewPath = '/panels/page/check_in';

    public function canAccess()
    {
        return $this->permissions->canApprovePageVersions() || $this->permissions->canEditPageContents();
    }

    public function on_start()
    {
        parent::on_start();
        if ($this->page) {
            $v = CollectionVersion::get($this->page, "RECENT");

            $this->set('publishDate', $v->getPublishDate());
            $this->set('publishErrors', $this->checkForPublishing());
            $this->set('timezone', $this->getTimezone());
        }
    }

    private function getTimezone()
    {
        if (Config::get('concrete.misc.user_timezones')) {
            $user = new User();
            $userInfo = $user->getUserInfoObject();

            return $userInfo->getUserTimezone();
        }

        return Config::get('app.timezone');
    }

    protected function checkForPublishing()
    {
        $c = $this->page;
        // verify this page type has all the items necessary to be approved.
        $e = Loader::helper('validation/error');
        if ($c->isPageDraft()) {
            if (!$c->getPageDraftTargetParentPageID()) {
                $e->add(t('You haven\'t chosen where to publish this page.'));
            }
        }
        $pagetype = $c->getPageTypeObject();
        if (is_object($pagetype)) {
            $validator = $pagetype->getPageTypeValidatorObject();
            $e->add($validator->validatePublishDraftRequest($c));
        }

        if ($c->isPageDraft() && !$e->has()) {
            $targetParentID = $c->getPageDraftTargetParentPageID();
            if ($targetParentID) {
                $tp = Page::getByID($targetParentID, 'ACTIVE');
                $pp = new Permissions($tp);
                if (!is_object($tp) || $tp->isError()) {
                    $e->add(t('Invalid target page.'));
                } else {
                    if (!$pp->canAddSubCollection($pagetype)) {
                        $e->add(
                            t(
                                'You do not have permissions to add a page of this type in the selected location.'
                            )
                        );
                    }
                }
            }
        }

        return $e;
    }

    public function submit()
    {
        if ($this->validateAction()) {
            $c = $this->page;
            $u = new User();
            $v = CollectionVersion::get($c, "RECENT");
            $v->setComment($_REQUEST['comments']);
            $pr = new PageEditResponse();
            if (($this->request->request->get('action') == 'publish'
                    || $this->request->request->get('action') == 'schedule')
                && $this->permissions->canApprovePageVersions()
            ) {
                $e = $this->checkForPublishing();
                $pr->setError($e);
                if (!$e->has()) {
                    $pkr = new ApprovePagePageWorkflowRequest();
                    $pkr->setRequestedPage($c);
                    $pkr->setRequestedVersionID($v->getVersionID());
                    $pkr->setRequesterUserID($u->getUserID());
                    $u->unloadCollectionEdit($c);

                    if ($this->request->request->get('action') == 'schedule') {
                        $dateTime = new DateTime();
                        $publishDateTime = $dateTime->translate('check-in-scheduler');
                        $pkr->scheduleVersion($publishDateTime);
                    }

                    $response = $pkr->trigger();

                    if ($c->isPageDraft()) {
                        $pagetype = $c->getPageTypeObject();
                        $pagetype->publish($c);
                    }
                }
            } else {
                if ($this->request->request->get('action') == 'discard') {
                    if ($c->isPageDraft() && $this->permissions->canDeletePage()) {
                        $this->page->delete();
                        $u = new User();
                        $cID = $u->getPreviousFrontendPageID();
                        $pr->setRedirectURL(DIR_REL . '/' . DISPATCHER_FILENAME . '?cID=' . $cID);
                    } else {
                        if ($v->canDiscard()) {
                            $v->discard();
                        }
                    }
                } else {
                    $v->removeNewStatus();
                }
            }
            $nc = Page::getByID($c->getCollectionID(), $v->getVersionID());
            $u->unloadCollectionEdit();
            $pr->setRedirectURL(Loader::helper('navigation')->getLinkToCollection($nc, true));
            $pr->outputJSON();
        }
    }

    protected function validateAction()
    {
        if (parent::validateAction()) {
            if ($this->permissions->canEditPageContents() || $this->permissions->canEditPageProperties(
                ) || $this->permissions->canApprovePageVersions()
            ) {
                return true;
            }
        }
    }
}
