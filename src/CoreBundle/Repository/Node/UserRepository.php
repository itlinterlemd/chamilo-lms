<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Repository\Node;

use Chamilo\CoreBundle\Entity\AccessUrl;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\ExtraField;
use Chamilo\CoreBundle\Entity\ExtraFieldValues;
use Chamilo\CoreBundle\Entity\Message;
use Chamilo\CoreBundle\Entity\ResourceNode;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\Tag;
use Chamilo\CoreBundle\Entity\TrackELogin;
use Chamilo\CoreBundle\Entity\TrackEOnline;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Entity\Usergroup;
use Chamilo\CoreBundle\Entity\UsergroupRelUser;
use Chamilo\CoreBundle\Entity\UserRelTag;
use Chamilo\CoreBundle\Entity\UserRelUser;
use Chamilo\CoreBundle\Repository\ResourceRepository;
use Datetime;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

use const MB_CASE_LOWER;

class UserRepository extends ResourceRepository implements PasswordUpgraderInterface
{
    protected ?UserPasswordHasherInterface $hasher = null;

    public const USER_IMAGE_SIZE_SMALL = 1;
    public const USER_IMAGE_SIZE_MEDIUM = 2;
    public const USER_IMAGE_SIZE_BIG = 3;
    public const USER_IMAGE_SIZE_ORIGINAL = 4;

    public function __construct(
        ManagerRegistry $registry,
        private readonly IllustrationRepository $illustrationRepository
    ) {
        parent::__construct($registry, User::class);
    }

    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneBy([
            'username' => $identifier,
        ]);
    }

    public function setHasher(UserPasswordHasherInterface $hasher): void
    {
        $this->hasher = $hasher;
    }

    public function createUser(): User
    {
        return new User();
    }

    public function updateUser(User $user, bool $andFlush = true): void
    {
        $this->updateCanonicalFields($user);
        $this->updatePassword($user);
        $this->getEntityManager()->persist($user);
        if ($andFlush) {
            $this->getEntityManager()->flush();
        }
    }

    public function canonicalize(string $string): string
    {
        $encoding = mb_detect_encoding($string, mb_detect_order(), true);

        return $encoding
            ? mb_convert_case($string, MB_CASE_LOWER, $encoding)
            : mb_convert_case($string, MB_CASE_LOWER);
    }

    public function updateCanonicalFields(User $user): void
    {
        $user->setUsernameCanonical($this->canonicalize($user->getUsername()));
        $user->setEmailCanonical($this->canonicalize($user->getEmail()));
    }

    public function updatePassword(User $user): void
    {
        $password = (string) $user->getPlainPassword();
        if ('' !== $password) {
            $password = $this->hasher->hashPassword($user, $password);
            $user->setPassword($password);
            $user->eraseCredentials();
        }
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        /** @var User $user */
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function getRootUser(): User
    {
        $qb = $this->createQueryBuilder('u');
        $qb
            ->innerJoin(
                'u.resourceNode',
                'r'
            )
        ;
        $qb
            ->where('r.creator = u')
            ->andWhere('r.parent IS NULL')
            ->getFirstResult()
        ;

        $rootUser = $qb->getQuery()->getSingleResult();

        if (null === $rootUser) {
            throw new UserNotFoundException('Root user not found');
        }

        return $rootUser;
    }

    public function deleteUser(User $user): void
    {
        $em = $this->getEntityManager();
        $type = $user->getResourceNode()->getResourceType();
        $rootUser = $this->getRootUser();

        // User children will be set to the root user.
        $criteria = Criteria::create()->where(Criteria::expr()->eq('resourceType', $type));
        $userNodeCreatedList = $user->getResourceNodes()->matching($criteria);

        /** @var ResourceNode $userCreated */
        foreach ($userNodeCreatedList as $userCreated) {
            $userCreated->setCreator($rootUser);
        }

        $em->remove($user->getResourceNode());

        foreach ($user->getGroups() as $group) {
            $user->removeGroup($group);
        }

        $em->remove($user);
        $em->flush();
    }

    public function addUserToResourceNode(int $userId, int $creatorId): ResourceNode
    {
        /** @var User $user */
        $user = $this->find($userId);
        $creator = $this->find($creatorId);

        $resourceNode = (new ResourceNode())
            ->setTitle($user->getUsername())
            ->setCreator($creator)
            ->setResourceType($this->getResourceType())
            // ->setParent($resourceNode)
        ;

        $user->setResourceNode($resourceNode);

        $this->getEntityManager()->persist($resourceNode);
        $this->getEntityManager()->persist($user);

        return $resourceNode;
    }

    public function addRoleListQueryBuilder(array $roles, ?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        if (!empty($roles)) {
            $orX = $qb->expr()->orX();
            foreach ($roles as $role) {
                $orX->add($qb->expr()->like('u.roles', ':'.$role));
                $qb->setParameter($role, '%'.$role.'%');
            }
            $qb->andWhere($orX);
        }

        return $qb;
    }

    public function findByUsername(string $username): ?User
    {
        $user = $this->findOneBy([
            'username' => $username,
        ]);

        if (null === $user) {
            throw new UserNotFoundException(sprintf("User with id '%s' not found.", $username));
        }

        return $user;
    }

    /**
     * Get a filtered list of user by role and (optionally) access url.
     *
     * @param string $keyword     The query to filter
     * @param int    $accessUrlId The access URL ID
     *
     * @return User[]
     */
    public function findByRole(string $role, string $keyword, int $accessUrlId = 0)
    {
        $qb = $this->createQueryBuilder('u');

        $this->addActiveAndNotAnonUserQueryBuilder($qb);
        $this->addAccessUrlQueryBuilder($accessUrlId, $qb);
        $this->addRoleQueryBuilder($role, $qb);
        $this->addSearchByKeywordQueryBuilder($keyword, $qb);

        return $qb->getQuery()->getResult();
    }

    public function findByRoleList(array $roleList, string $keyword, int $accessUrlId = 0)
    {
        $qb = $this->createQueryBuilder('u');

        $this->addActiveAndNotAnonUserQueryBuilder($qb);
        $this->addAccessUrlQueryBuilder($accessUrlId, $qb);
        $this->addRoleListQueryBuilder($roleList, $qb);
        $this->addSearchByKeywordQueryBuilder($keyword, $qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get the coaches for a course within a session.
     *
     * @return Collection|array
     */
    public function getCoachesForSessionCourse(Session $session, Course $course)
    {
        $qb = $this->createQueryBuilder('u');

        $qb->select('u')
            ->innerJoin(
                'ChamiloCoreBundle:SessionRelCourseRelUser',
                'scu',
                Join::WITH,
                'scu.user = u'
            )
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('scu.session', $session->getId()),
                    $qb->expr()->eq('scu.course', $course->getId()),
                    $qb->expr()->eq('scu.status', Session::COURSE_COACH)
                )
            )
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * Get the sessions admins for a user.
     *
     * @return array
     */
    public function getSessionAdmins(User $user)
    {
        $qb = $this->createQueryBuilder('u');
        $qb
            ->distinct()
            ->innerJoin(
                'ChamiloCoreBundle:SessionRelUser',
                'su',
                Join::WITH,
                'u = su.user'
            )
            ->innerJoin(
                'ChamiloCoreBundle:SessionRelCourseRelUser',
                'scu',
                Join::WITH,
                'su.session = scu.session'
            )
            ->where(
                $qb->expr()->eq('scu.user', $user->getId())
            )
            ->andWhere(
                $qb->expr()->eq('su.relationType', Session::DRH)
            )
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * Get number of users in URL.
     *
     * @return int
     */
    public function getCountUsersByUrl(AccessUrl $url)
    {
        return $this->createQueryBuilder('u')
            ->select('COUNT(u)')
            ->innerJoin('u.portals', 'p')
            ->where('p.url = :url')
            ->setParameters([
                'url' => $url,
            ])
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Get number of users in URL.
     *
     * @return int
     */
    public function getCountTeachersByUrl(AccessUrl $url)
    {
        $qb = $this->createQueryBuilder('u');

        $qb
            ->select('COUNT(u)')
            ->innerJoin('u.portals', 'p')
            ->where('p.url = :url')
            ->setParameters([
                'url' => $url,
            ])
        ;

        $this->addRoleListQueryBuilder(['ROLE_TEACHER'], $qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Find potential users to send a message.
     *
     * @todo remove  api_is_platform_admin
     *
     * @param int    $currentUserId The current user ID
     * @param string $searchFilter  Optional. The search text to filter the user list
     * @param int    $limit         Optional. Sets the maximum number of results to retrieve
     *
     * @return User[]
     */
    public function findUsersToSendMessage(int $currentUserId, ?string $searchFilter = null, int $limit = 10)
    {
        $allowSendMessageToAllUsers = api_get_setting('allow_send_message_to_all_platform_users');
        $accessUrlId = api_get_multiple_access_url() ? api_get_current_access_url_id() : 1;

        $messageTool = 'true' === api_get_setting('allow_message_tool');
        if (!$messageTool) {
            return [];
        }

        $qb = $this->createQueryBuilder('u');
        $this->addActiveAndNotAnonUserQueryBuilder($qb);
        $this->addAccessUrlQueryBuilder($accessUrlId, $qb);

        $dql = null;
        if ('true' === api_get_setting('allow_social_tool')) {
            // All users
            if ('true' === $allowSendMessageToAllUsers || api_is_platform_admin()) {
                $this->addNotCurrentUserQueryBuilder($currentUserId, $qb);
            /*$dql = "SELECT DISTINCT U
                    FROM ChamiloCoreBundle:User U
                    LEFT JOIN ChamiloCoreBundle:AccessUrlRelUser R
                    WITH U = R.user
                    WHERE
                        U.active = 1 AND
                        U.status != 6  AND
                        U.id != {$currentUserId} AND
                        R.url = {$accessUrlId}";*/
            } else {
                $this->addOnlyMyFriendsQueryBuilder($currentUserId, $qb);
                /*$dql = 'SELECT DISTINCT U
                        FROM ChamiloCoreBundle:AccessUrlRelUser R, ChamiloCoreBundle:UserRelUser UF
                        INNER JOIN ChamiloCoreBundle:User AS U
                        WITH UF.friendUserId = U
                        WHERE
                            U.active = 1 AND
                            U.status != 6 AND
                            UF.relationType NOT IN('.USER_RELATION_TYPE_DELETED.', '.USER_RELATION_TYPE_RRHH.") AND
                            UF.user = {$currentUserId} AND
                            UF.friendUserId != {$currentUserId} AND
                            U = R.user AND
                            R.url = {$accessUrlId}";*/
            }
        } else {
            if ('true' === $allowSendMessageToAllUsers) {
                $this->addNotCurrentUserQueryBuilder($currentUserId, $qb);
            } else {
                return [];
            }

            /*else {
                $time_limit = (int) api_get_setting('time_limit_whosonline');
                $online_time = time() - ($time_limit * 60);
                $limit_date = api_get_utc_datetime($online_time);
                $dql = "SELECT DISTINCT U
                        FROM ChamiloCoreBundle:User U
                        INNER JOIN ChamiloCoreBundle:TrackEOnline T
                        WITH U.id = T.loginUserId
                        WHERE
                          U.active = 1 AND
                          T.loginDate >= '".$limit_date."'";
            }*/
        }

        if (!empty($searchFilter)) {
            $this->addSearchByKeywordQueryBuilder($searchFilter, $qb);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get the list of HRM who have assigned this user.
     *
     * @return User[]
     */
    public function getAssignedHrmUserList(int $userId, int $urlId)
    {
        $qb = $this->createQueryBuilder('u');
        $this->addAccessUrlQueryBuilder($urlId, $qb);
        $this->addActiveAndNotAnonUserQueryBuilder($qb);
        $this->addUserRelUserQueryBuilder($userId, UserRelUser::USER_RELATION_TYPE_RRHH, $qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get the last login from the track_e_login table.
     * This might be different from user.last_login in the case of legacy users
     * as user.last_login was only implemented in 1.10 version with a default
     * value of NULL (not the last record from track_e_login).
     *
     * @return null|TrackELogin
     */
    public function getLastLogin(User $user)
    {
        $qb = $this->createQueryBuilder('u');

        return $qb
            ->select('l')
            ->innerJoin('u.logins', 'l')
            ->where(
                $qb->expr()->eq('l.user', $user)
            )
            ->setMaxResults(1)
            ->orderBy('u.loginDate', Criteria::DESC)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function addAccessUrlQueryBuilder(int $accessUrlId, ?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        $qb
            ->innerJoin('u.portals', 'p')
            ->andWhere('p.url = :url')
            ->setParameter('url', $accessUrlId, Types::INTEGER)
        ;

        return $qb;
    }

    public function addActiveAndNotAnonUserQueryBuilder(?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        $qb
            ->andWhere('u.active = 1')
            ->andWhere('u.status <> :status')
            ->setParameter('status', User::ANONYMOUS, Types::INTEGER)
        ;

        return $qb;
    }

    public function addExpirationDateQueryBuilder(?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        $qb
            ->andWhere('u.expirationDate IS NULL OR u.expirationDate > :now')
            ->setParameter('now', new Datetime(), Types::DATETIME_MUTABLE)
        ;

        return $qb;
    }

    private function addRoleQueryBuilder(string $role, ?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        $qb
            ->andWhere('u.roles LIKE :roles')
            ->setParameter('roles', '%"'.$role.'"%', Types::STRING)
        ;

        return $qb;
    }

    private function addSearchByKeywordQueryBuilder(string $keyword, ?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        $qb
            ->andWhere('
                u.firstname LIKE :keyword OR
                u.lastname LIKE :keyword OR
                u.email LIKE :keyword OR
                u.username LIKE :keyword
            ')
            ->setParameter('keyword', "%$keyword%", Types::STRING)
            ->orderBy('u.firstname', Criteria::ASC)
        ;

        return $qb;
    }

    private function addUserRelUserQueryBuilder(int $userId, int $relationType, ?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        $qb->leftJoin('u.friends', 'relations');
        $qb
            ->andWhere('relations.relationType = :relationType')
            ->andWhere('relations.user = :userRelation AND relations.friend <> :userRelation')
            ->setParameter('relationType', $relationType)
            ->setParameter('userRelation', $userId)
        ;

        return $qb;
    }

    private function addOnlyMyFriendsQueryBuilder(int $userId, ?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        $qb
            ->leftJoin('u.friends', 'relations')
            ->andWhere(
                $qb->expr()->notIn(
                    'relations.relationType',
                    [UserRelUser::USER_RELATION_TYPE_DELETED, UserRelUser::USER_RELATION_TYPE_RRHH]
                )
            )
            ->andWhere('relations.user = :user AND relations.friend <> :user')
            ->setParameter('user', $userId, Types::INTEGER)
        ;

        return $qb;
    }

    private function addNotCurrentUserQueryBuilder(int $userId, ?QueryBuilder $qb = null): QueryBuilder
    {
        $qb = $this->getOrCreateQueryBuilder($qb, 'u');
        $qb
            ->andWhere('u.id <> :id')
            ->setParameter('id', $userId, Types::INTEGER)
        ;

        return $qb;
    }

    public function getFriendsNotInGroup(int $userId, int $groupId)
    {
        $entityManager = $this->getEntityManager();

        $subQueryBuilder = $entityManager->createQueryBuilder();
        $subQuery = $subQueryBuilder
            ->select('IDENTITY(ugr.user)')
            ->from(UsergroupRelUser::class, 'ugr')
            ->where('ugr.usergroup = :subGroupId')
            ->andWhere('ugr.relationType IN (:subRelationTypes)')
            ->getDQL()
        ;

        $queryBuilder = $entityManager->createQueryBuilder();
        $query = $queryBuilder
            ->select('u')
            ->from(User::class, 'u')
            ->leftJoin('u.friendsWithMe', 'uruf')
            ->leftJoin('u.friends', 'urut')
            ->where('uruf.friend = :userId OR urut.user = :userId')
            ->andWhere($queryBuilder->expr()->notIn('u.id', $subQuery))
            ->setParameter('userId', $userId)
            ->setParameter('subGroupId', $groupId)
            ->setParameter('subRelationTypes', [Usergroup::GROUP_USER_PERMISSION_PENDING_INVITATION])
            ->getQuery()
        ;

        return $query->getResult();
    }

    public function getExtraUserData(int $userId, bool $prefix = false, bool $allVisibility = true, bool $splitMultiple = false, ?int $fieldFilter = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        // Start building the query
        $qb->select('ef.id', 'ef.variable as fvar', 'ef.valueType as type', 'efv.fieldValue as fval', 'ef.defaultValue as fval_df')
            ->from(ExtraField::class, 'ef')
            ->leftJoin(ExtraFieldValues::class, 'efv', Join::WITH, 'efv.field = ef.id AND efv.itemId = :userId')
            ->where('ef.itemType = :itemType')
            ->setParameter('userId', $userId)
            ->setParameter('itemType', ExtraField::USER_FIELD_TYPE)
        ;

        // Apply visibility filters
        if (!$allVisibility) {
            $qb->andWhere('ef.visibleToSelf = true');
        }

        // Apply field filter if provided
        if (null !== $fieldFilter) {
            $qb->andWhere('ef.id = :fieldFilter')
                ->setParameter('fieldFilter', $fieldFilter)
            ;
        }

        // Order by field order
        $qb->orderBy('ef.fieldOrder', 'ASC');

        // Execute the query
        $results = $qb->getQuery()->getResult();

        // Process results
        $extraData = [];
        foreach ($results as $row) {
            $value = $row['fval'] ?? $row['fval_df'];

            // Handle multiple values if necessary
            if ($splitMultiple && \in_array($row['type'], [ExtraField::USER_FIELD_TYPE_SELECT_MULTIPLE], true)) {
                $value = explode(';', $value);
            }

            // Handle prefix if needed
            $key = $prefix ? 'extra_'.$row['fvar'] : $row['fvar'];

            // Special handling for certain field types
            if (ExtraField::USER_FIELD_TYPE_TAG == $row['type']) {
                // Implement your logic to handle tags
            } elseif (ExtraField::USER_FIELD_TYPE_RADIO == $row['type'] && $prefix) {
                $extraData[$key][$key] = $value;
            } else {
                $extraData[$key] = $value;
            }
        }

        return $extraData;
    }

    public function getExtraUserDataByField(int $userId, string $fieldVariable, bool $allVisibility = true): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb->select('e.id, e.variable, e.valueType, v.fieldValue')
            ->from(ExtraFieldValues::class, 'v')
            ->innerJoin('v.field', 'e')
            ->where('v.itemId = :userId')
            ->andWhere('e.variable = :fieldVariable')
            ->andWhere('e.itemType = :itemType')
            ->setParameters([
                'userId' => $userId,
                'fieldVariable' => $fieldVariable,
                'itemType' => ExtraField::USER_FIELD_TYPE,
            ])
        ;

        if (!$allVisibility) {
            $qb->andWhere('e.visibleToSelf = true');
        }

        $qb->orderBy('e.fieldOrder', 'ASC');

        $result = $qb->getQuery()->getResult();

        $extraData = [];
        foreach ($result as $row) {
            $value = $row['fieldValue'];
            if (ExtraField::USER_FIELD_TYPE_SELECT_MULTIPLE == $row['valueType']) {
                $value = explode(';', $row['fieldValue']);
            }

            $extraData[$row['variable']] = $value;
        }

        return $extraData;
    }

    public function searchUsersByTags(
        string $tag,
        ?int $excludeUserId = null,
        int $fieldId = 0,
        int $from = 0,
        int $number_of_items = 10,
        bool $getCount = false
    ): array {
        $qb = $this->createQueryBuilder('u');

        if ($getCount) {
            $qb->select('COUNT(DISTINCT u.id)');
        } else {
            $qb->select('DISTINCT u.id, u.username, u.firstname, u.lastname, u.email, u.pictureUri, u.status');
        }

        $qb->innerJoin('u.portals', 'urlRelUser')
            ->leftJoin(UserRelTag::class, 'uv', 'WITH', 'u = uv.user')
            ->leftJoin(Tag::class, 'ut', 'WITH', 'uv.tag = ut')
        ;

        if (0 !== $fieldId) {
            $qb->andWhere('ut.field = :fieldId')
                ->setParameter('fieldId', $fieldId)
            ;
        }

        if (null !== $excludeUserId) {
            $qb->andWhere('u.id != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId)
            ;
        }

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('ut.tag', ':tag'),
                $qb->expr()->like('u.firstname', ':likeTag'),
                $qb->expr()->like('u.lastname', ':likeTag'),
                $qb->expr()->like('u.username', ':likeTag'),
                $qb->expr()->like(
                    $qb->expr()->concat('u.firstname', $qb->expr()->literal(' '), 'u.lastname'),
                    ':likeTag'
                ),
                $qb->expr()->like(
                    $qb->expr()->concat('u.lastname', $qb->expr()->literal(' '), 'u.firstname'),
                    ':likeTag'
                )
            )
        )
            ->setParameter('tag', $tag.'%')
            ->setParameter('likeTag', '%'.$tag.'%')
        ;

        // Only active users and not anonymous
        $qb->andWhere('u.active = :active')
            ->andWhere('u.status != :anonymous')
            ->setParameter('active', true)
            ->setParameter('anonymous', 6)
        ;

        if (!$getCount) {
            $qb->orderBy('u.username')
                ->setFirstResult($from)
                ->setMaxResults($number_of_items)
            ;
        }

        return $getCount ? $qb->getQuery()->getSingleScalarResult() : $qb->getQuery()->getResult();
    }

    public function getUserRelationWithType(int $userId, int $friendId): ?array
    {
        $qb = $this->createQueryBuilder('u');
        $qb->select('u.id AS userId', 'u.username AS userName', 'ur.relationType', 'f.id AS friendId', 'f.username AS friendName')
            ->innerJoin('u.friends', 'ur')
            ->innerJoin('ur.friend', 'f')
            ->where('u.id = :userId AND f.id = :friendId')
            ->setParameter('userId', $userId)
            ->setParameter('friendId', $friendId)
            ->setMaxResults(1)
        ;

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function relateUsers(User $user1, User $user2, int $relationType): void
    {
        $em = $this->getEntityManager();

        $existingRelation = $em->getRepository(UserRelUser::class)->findOneBy([
            'user' => $user1,
            'friend' => $user2,
        ]);

        if (!$existingRelation) {
            $newRelation = new UserRelUser();
            $newRelation->setUser($user1);
            $newRelation->setFriend($user2);
            $newRelation->setRelationType($relationType);
            $em->persist($newRelation);
        } else {
            $existingRelation->setRelationType($relationType);
        }

        $existingRelationInverse = $em->getRepository(UserRelUser::class)->findOneBy([
            'user' => $user2,
            'friend' => $user1,
        ]);

        if (!$existingRelationInverse) {
            $newRelationInverse = new UserRelUser();
            $newRelationInverse->setUser($user2);
            $newRelationInverse->setFriend($user1);
            $newRelationInverse->setRelationType($relationType);
            $em->persist($newRelationInverse);
        } else {
            $existingRelationInverse->setRelationType($relationType);
        }

        $em->flush();
    }

    public function getUserPicture(
        $userId,
        int $size = self::USER_IMAGE_SIZE_MEDIUM,
        $addRandomId = true,
    ) {
        $user = $this->find($userId);
        if (!$user) {
            return '/img/icons/64/unknown.png';
        }

        switch ($size) {
            case self::USER_IMAGE_SIZE_SMALL:
                $width = 32;

                break;

            case self::USER_IMAGE_SIZE_MEDIUM:
                $width = 64;

                break;

            case self::USER_IMAGE_SIZE_BIG:
                $width = 128;

                break;

            case self::USER_IMAGE_SIZE_ORIGINAL:
            default:
                $width = 0;

                break;
        }

        $url = $this->illustrationRepository->getIllustrationUrl($user);
        $params = [];
        if (!empty($width)) {
            $params['w'] = $width;
        }

        if ($addRandomId) {
            $params['rand'] = uniqid('u_', true);
        }

        $paramsToString = '';
        if (!empty($params)) {
            $paramsToString = '?'.http_build_query($params);
        }

        return $url.$paramsToString;
    }
}
