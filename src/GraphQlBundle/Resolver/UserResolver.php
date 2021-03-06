<?php

/* For licensing terms, see /license.txt */

namespace Chamilo\GraphQlBundle\Resolver;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\GraphQlBundle\Traits\GraphQLTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Overblog\GraphQLBundle\Definition\Argument;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;

/**
 * Class UserResolver.
 */
class UserResolver implements ContainerAwareInterface
{
    use GraphQLTrait;

    /**
     * @return string
     */
    public function getEmail(User $user)
    {
        $this->protectCurrentUserData($user);

        $showEmail = 'true' === $this->settingsManager->getSetting('display.show_email_addresses');

        if (!$showEmail) {
            return '';
        }

        return $user->getEmail();
    }

    /**
     * @return ArrayCollection
     */
    public function getMessages(User $user, Argument $args)
    {
        $this->protectCurrentUserData($user);

        return $user->getUnreadReceivedMessages($args['lastId']);
    }

    /**
     * @return array
     */
    public function getMessageContacts(User $user, Argument $args)
    {
        $this->protectCurrentUserData($user);

        if (strlen($args['filter']) < 3) {
            return [];
        }

        $usersRepo = $this->em->getRepository('ChamiloCoreBundle:User');

        return $usersRepo->findUsersToSendMessage($user->getId(), $args['filter']);
    }

    /**
     * @return array
     */
    public function getCourses(User $user, Argument $args, \ArrayObject $context)
    {
        $context->offsetSet('session', null);

        $this->protectCurrentUserData($user);

        $coursesInfo = \CourseManager::get_courses_list_by_user_id($user->getId());
        $coursesRepo = $this->em->getRepository('ChamiloCoreBundle:Course');
        $courses = [];

        foreach ($coursesInfo as $courseInfo) {
            /** @var Course $course */
            $course = $coursesRepo->find($courseInfo['real_id']);

            if ($course) {
                $courses[] = $course;
            }
        }

        return $courses;
    }

    /**
     * @return array
     */
    public function getSessions(User $user)
    {
        $this->protectCurrentUserData($user);

        $sessionsId = $this->findUserSessions($user);

        if (empty($sessionsId)) {
            return [];
        }

        $qb = $this->em->createQueryBuilder();

        return $qb
            ->select('s')
            ->from('ChamiloCoreBundle:Session', 's')
            ->where(
                $qb->expr()->in('s.id', $sessionsId)
            )
            ->getQuery()
            ->getResult();
    }

    /**
     * @todo Based on UserManager::get_sessions_by_category. Review to integrate Symfony
     *
     * @return array
     */
    private function findUserSessions(User $user)
    {
        $allowOrder = api_get_configuration_value('session_list_order');
        $showAllSessions = true === api_get_configuration_value('show_all_sessions_on_my_course_page');
        $orderBySettings = api_get_configuration_value('my_courses_session_order');

        $position = '';

        if ($allowOrder) {
            $position = ', s.position AS position ';
        }

        $now = api_get_utc_datetime(null, false, true);

        $dql = "SELECT DISTINCT
                    s.id,
                    s.accessEndDate AS access_end_date,
                    s.duration,
                    CASE WHEN s.accessEndDate IS NULL THEN 1 ELSE 0 END HIDDEN _isFieldNull
                    $position
                FROM ChamiloCoreBundle:Session AS s
                LEFT JOIN ChamiloCoreBundle:SessionRelCourseRelUser AS scu WITH scu.session = s
                INNER JOIN ChamiloCoreBundle:AccessUrlRelSession AS url WITH url.session = s.id
                LEFT JOIN ChamiloCoreBundle:SessionCategory AS sc WITH s.category = sc
                WHERE (scu.user = :user OR s.generalCoach = :user) AND url.url = :url";

        $order = 'ORDER BY sc.name, s.name';

        if ($showAllSessions) {
            $order = 'ORDER BY s.accessStartDate';
        }

        if ($allowOrder) {
            $order = 'ORDER BY s.position';
        }

        if (!empty($orderBySettings) && isset($orderBySettings['field']) && isset($orderBySettings['order'])) {
            $field = $orderBySettings['field'];
            $orderSetting = $orderBySettings['order'];

            switch ($field) {
                case 'start_date':
                    $order = "ORDER BY s.accessStartDate $orderSetting";

                    break;
                case 'end_date':
                    $order = " ORDER BY s.accessEndDate $orderSetting ";
                    if ('asc' == $orderSetting) {
                        // Put null values at the end
                        // https://stackoverflow.com/questions/12652034/how-can-i-order-by-null-in-dql
                        $order = 'ORDER BY _isFieldNull asc, s.accessEndDate asc';
                    }

                    break;
            }
        }

        $results = [];
        $rows = $this->em
            ->createQuery("$dql $order")
            ->setParameters(
                [
                    'user' => $user->getId(),
                    'url' => api_get_current_access_url_id(),
                ]
            )
            ->getResult();

        foreach ($rows as $row) {
            $coachList = \SessionManager::getCoachesBySession($row['id']);
            $courseList = \UserManager::get_courses_list_by_session(
                $user->getId(),
                $row['id']
            );
            $daysLeft = \SessionManager::getDayLeftInSession(
                ['id' => $row['id'], 'duration' => $row['duration']],
                $user->getId()
            );
            $isGeneralCoach = \SessionManager::user_is_general_coach($user->getId(), $row['id']);
            $isCoachOfCourse = in_array($user->getId(), $coachList);

            if (!$isGeneralCoach && !$isCoachOfCourse) {
                // Teachers can access the session depending in the access_coach date
                if ($row['duration']) {
                    if ($daysLeft <= 0) {
                        continue;
                    }
                } else {
                    if (isset($row['access_end_date']) && !empty($row['access_end_date'])) {
                        if ($row['access_end_date'] <= $now) {
                            continue;
                        }
                    }
                }
            }

            $visibility = api_get_session_visibility($row['id'], null, false);

            if (SESSION_VISIBLE != $visibility) {
                // Course Coach session visibility.
                $blockedCourseCount = 0;
                $closedVisibilityList = [COURSE_VISIBILITY_CLOSED, COURSE_VISIBILITY_HIDDEN];
                $sessionCourseVisibility = SESSION_INVISIBLE;

                foreach ($courseList as $course) {
                    // Checking session visibility
                    $sessionCourseVisibility = api_get_session_visibility(
                        $row['id'],
                        $course['real_id'],
                        false
                    );

                    $courseIsVisible = !in_array($course['visibility'], $closedVisibilityList);

                    if (false === $courseIsVisible || SESSION_INVISIBLE == $sessionCourseVisibility) {
                        $blockedCourseCount++;
                    }
                }

                // If all courses are blocked then no show in the list.
                if ($blockedCourseCount !== count($courseList)) {
                    $visibility = $sessionCourseVisibility;
                }
            }

            if (SESSION_INVISIBLE == $visibility) {
                continue;
            }

            $results[] = $row['id'];
        }

        return $results;
    }
}
