<?php

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Entity;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiSubresource;
use Chamilo\CoreBundle\Security\Authorization\Voter\ResourceNodeVoter;
use Chamilo\CourseBundle\Entity\CGroup;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\MappedSuperclass
 * @ORM\HasLifecycleCallbacks
 * @ORM\EntityListeners({"Chamilo\CoreBundle\Entity\Listener\ResourceListener"})
 */
abstract class AbstractResource
{
    /**
     * @var string|null
     *
     * @ApiProperty(iri="http://schema.org/contentUrl")
     * @Groups({"resource_file:read", "resource_node:read", "document:read", "media_object_read"})
     */
    public $contentUrl;

    /**
     * @var string|null
     *
     * @ApiProperty(iri="http://schema.org/contentUrl")
     * @Groups({"resource_file:read", "resource_node:read", "document:read", "media_object_read"})
     */
    public $downloadUrl;

    /**
     * @var string|null
     *
     * @Groups({"resource_file:read", "resource_node:read", "document:read", "document:write", "media_object_read"})
     */
    public $contentFile;

    /**
     * @Assert\Valid()
     * @ApiSubresource()
     * @Groups({"resource_node:read", "resource_node:write", "document:write" })
     * @ORM\OneToOne(
     *     targetEntity="Chamilo\CoreBundle\Entity\ResourceNode",
     *     cascade={"persist", "remove"},
     *     orphanRemoval=true
     * )
     * @ORM\JoinColumn(name="resource_node_id", referencedColumnName="id", onDelete="CASCADE")
     */
    public $resourceNode;

    /**
     * @Groups({"resource_node:read", "resource_node:write", "document:read", "document:write"})
     */
    public $parentResourceNode;

    /**
     * @ApiProperty(iri="http://schema.org/image")
     */
    public $uploadFile;

    /** @var AbstractResource */
    public $parentResource;

    /**
     * @Groups({"resource_node:read", "document:read"})
     */
    public $resourceLinkListFromEntity;

    /**
     * Use when sending a request to Api platform.
     * Temporal array that saves the resource link list that will be filled by CreateResourceNodeFileAction.php.
     *
     * @var array
     */
    public $resourceLinkList;

    /**
     * Use when sending request to Chamilo.
     * Temporal array of objects locates the resource link list that will be filled by CreateResourceNodeFileAction.php.
     *
     * @var ResourceLink[]
     */
    public $resourceLinkEntityList;

    abstract public function getResourceName(): string;

    abstract public function setResourceName(string $name);

    public function getResourceLinkEntityList()
    {
        return $this->resourceLinkEntityList;
    }

    public function addLink(ResourceLink $link)
    {
        $this->resourceLinkEntityList[] = $link;

        return $this;
    }

    public function addCourseLink(Course $course, Session $session = null, CGroup $group = null, int $visibility = ResourceLink::VISIBILITY_PUBLISHED)
    {
        if (null === $this->getParent()) {
            throw new \Exception('addCourseLink requires to set the parent first.');
        }

        $resourceLink = new ResourceLink();
        $resourceLink
            ->setVisibility($visibility)
            ->setCourse($course)
            ->setSession($session)
            ->setGroup($group)
        ;

        $rights = [];
        switch ($visibility) {
            case ResourceLink::VISIBILITY_PENDING:
            case ResourceLink::VISIBILITY_DRAFT:
                $editorMask = ResourceNodeVoter::getEditorMask();
                $resourceRight = new ResourceRight();
                $resourceRight
                    ->setMask($editorMask)
                    ->setRole(ResourceNodeVoter::ROLE_CURRENT_COURSE_TEACHER)
                ;
                $rights[] = $resourceRight;

                break;
        }

        if (!empty($rights)) {
            foreach ($rights as $right) {
                $resourceLink->addResourceRight($right);
            }
        }

        if ($this->hasResourceNode()) {
            $resourceNode = $this->getResourceNode();
            $exists = $resourceNode->getResourceLinks()->exists(
                function ($key, $element) use ($course, $session, $group) {
                    return
                        $course === $element->getCourse() &&
                        $session === $element->getSession() &&
                        $group === $element->getGroup();
                }
            );

            if ($exists) {
                return $this;
            }
            $resourceNode->addResourceLink($resourceLink);
        } else {
            $this->addLink($resourceLink);
        }

        return $this;
    }

    public function addGroupLink(Course $course, Session $session = null, CGroup $group = null)
    {
        $resourceLink = new ResourceLink();
        $resourceLink
            ->setCourse($course)
            ->setSession($session)
            ->setGroup($group)
            ->setVisibility(ResourceLink::VISIBILITY_PUBLISHED)
        ;

        if ($this->hasResourceNode()) {
            $resourceNode = $this->getResourceNode();
            $exists = $resourceNode->getResourceLinks()->exists(
                function ($key, $element) use ($group) {
                    if ($element->getGroup()) {
                        return $group->getIid() === $element->getGroup()->getIid();
                    }
                }
            );

            if ($exists) {
                return $this;
            }
            $resourceNode->addResourceLink($resourceLink);
        } else {
            $this->addLink($resourceLink);
        }

        return $this;
    }

    public function addUserLink(User $user, Course $course = null, Session $session = null, CGroup $group = null)
    {
        $resourceLink = new ResourceLink();
        $resourceLink
            ->setVisibility(ResourceLink::VISIBILITY_PUBLISHED)
            ->setUser($user)
            ->setCourse($course)
            ->setSession($session)
            ->setGroup($group)
        ;

        if ($this->hasResourceNode()) {
            $resourceNode = $this->getResourceNode();
            $exists = $resourceNode->getResourceLinks()->exists(
                function ($key, $element) use ($user) {
                    if ($element->hasUser()) {
                        return $user->getId() === $element->getUser()->getId();
                    }
                }
            );

            if ($exists) {
                error_log('Link already exist for user: '.$user->getUsername().', skipping');

                return $this;
            }

            error_log('New link can be added for user: '.$user->getUsername());
            $resourceNode->addResourceLink($resourceLink);
        } else {
            $this->addLink($resourceLink);
        }

        return $this;
    }

    public function setParent(AbstractResource $parent)
    {
        $this->parentResource = $parent;

        return $this;
    }

    public function getParent()
    {
        return $this->parentResource;
    }

    /**
     * @param array $userList User id list
     */
    public function addResourceToUserList(
        array $userList,
        Course $course = null,
        Session $session = null,
        CGroup $group = null
    ) {
        if (!empty($userList)) {
            foreach ($userList as $user) {
                $this->addUserLink($user, $course, $session, $group);
            }
        }

        return $this;
    }

    public function setResourceLinkArray(array $links)
    {
        $this->resourceLinkList = $links;

        return $this;
    }

    public function getResourceLinkArray()
    {
        return $this->resourceLinkList;
    }

    public function getResourceLinkListFromEntity()
    {
        return $this->resourceLinkListFromEntity;
    }

    public function setResourceLinkListFromEntity()
    {
        $resourceNode = $this->getResourceNode();
        $links = $resourceNode->getResourceLinks();
        $resourceLinkList = [];

        foreach ($links as $link) {
            $resourceLinkList[] = [
                'id' => $link->getId(),
                'session' => $link->getSession(),
                'course' => $link->getCourse(),
                'visibility' => $link->getVisibility(),
                'visibilityName' => $link->getVisibilityName(),
                'group' => $link->getGroup(),
                'userGroup' => $link->getUserGroup(),
            ];
        }
        $this->resourceLinkListFromEntity = $resourceLinkList;
    }

    public function hasParentResourceNode(): bool
    {
        return null !== $this->parentResourceNode;
    }

    public function setParentResourceNode($resourceNode): self
    {
        $this->parentResourceNode = $resourceNode;

        return $this;
    }

    public function getParentResourceNode()
    {
        return $this->parentResourceNode;
    }

    public function hasUploadFile(): bool
    {
        return null !== $this->uploadFile;
    }

    public function getUploadFile()
    {
        return $this->uploadFile;
    }

    public function setUploadFile($file): self
    {
        $this->uploadFile = $file;

        return $this;
    }

    public function setResourceNode(ResourceNode $resourceNode): ResourceInterface
    {
        $this->resourceNode = $resourceNode;

        return $this;
    }

    public function hasResourceNode(): bool
    {
        return $this->resourceNode instanceof ResourceNode;
    }

    public function getResourceNode(): ResourceNode
    {
        return $this->resourceNode;
    }

    public function getFirstResourceLink(): ?ResourceLink
    {
        $resourceNode = $this->getResourceNode();

        if ($resourceNode && $resourceNode->getResourceLinks()->count()) {
            $result = $resourceNode->getResourceLinks()->first();
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * See ResourceLink to see the visibility constants. Example: ResourceLink::VISIBILITY_DELETED.
     */
    public function getLinkVisibility(Course $course, Session $session = null): ?ResourceLink
    {
        return $this->getFirstResourceLinkFromCourseSession($course, $session)->getVisibility();
    }

    public function isVisible(Course $course, Session $session = null): bool
    {
        $link = $this->getFirstResourceLinkFromCourseSession($course, $session);
        if (null === $link) {
            return false;
        }

        return ResourceLink::VISIBILITY_PUBLISHED === $link->getVisibility();
    }

    public function getFirstResourceLinkFromCourseSession(Course $course, Session $session = null): ?ResourceLink
    {
        /*$criteria = Criteria::create();
        $criteria
            ->where(Criteria::expr()->eq('course', $course->getId()))
            ->andWhere(
                Criteria::expr()->eq('session', $session)
            )
            ->setFirstResult(0)
            ->setMaxResults(1)
        ;*/
        $resourceNode = $this->getResourceNode();
        $result = null;
        if ($resourceNode && $resourceNode->getResourceLinks()->count() > 0) {
            $links = $resourceNode->getResourceLinks();
            $found = false;
            $link = null;
            foreach ($links as $link) {
                if ($link->getCourse() === $course && $link->getSession() === $session) {
                    $found = true;
                    break;
                }
            }
            //$result = $links->matching($criteria)->count();
            //var_dump($result);
            if ($found) {
                return $link;
            }
        }

        return null;
    }

    public function getUsersAndGroupSubscribedToResource(): array
    {
        $users = [];
        $groups = [];
        $everyone = false;
        $links = $this->getResourceNode()->getResourceLinks();
        foreach ($links as $link) {
            if ($link->getUser()) {
                $users[] = $link->getUser()->getId();
            }
            if ($link->getGroup()) {
                $groups[] = $link->getGroup()->getIid();
            }
        }

        if (empty($users) && empty($groups)) {
            $everyone = true;
        }

        return [
            'everyone' => $everyone,
            'users' => $users,
            'groups' => $groups,
        ];
    }
}
