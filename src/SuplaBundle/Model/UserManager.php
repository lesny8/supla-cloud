<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.
 
 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use SuplaBundle\Entity\Schedule;
use SuplaBundle\Entity\User;
use SuplaBundle\Model\Schedule\ScheduleManager;
use SuplaBundle\Repository\UserRepository;
use Symfony\Component\Security\Core\Encoder\EncoderFactory;

class UserManager {
    use Transactional;

    protected $encoder_factory;
    /** @var UserRepository */
    protected $rep;
    protected $loc_man;
    protected $aid_man;
    /** @var ScheduleManager */
    private $scheduleManager;

    private $defaultClientsRegistrationTime;
    private $defaultIoDevicesRegistrationTime;
    /** @var EntityManagerInterface */
    private $entityManager;

    public function __construct(
        UserRepository $userRepository,
        EncoderFactory $encoder_factory,
        AccessIdManager $accessid_manager,
        LocationManager $location_manager,
        ScheduleManager $scheduleManager,
        int $defaultClientsRegistrationTime,
        int $defaultIoDevicesRegistrationTime
    ) {
        $this->encoder_factory = $encoder_factory;
        $this->rep = $userRepository;
        $this->loc_man = $location_manager;
        $this->aid_man = $accessid_manager;
        $this->scheduleManager = $scheduleManager;
        $this->defaultClientsRegistrationTime = $defaultClientsRegistrationTime;
        $this->defaultIoDevicesRegistrationTime = $defaultIoDevicesRegistrationTime;
    }

    public function create(User $user) {
        $this->setPassword($user->getPlainPassword(), $user);
        $user->genToken();
        $this->transactional(function (EntityManagerInterface $em) use ($user) {
            $em->persist($user);
        });
    }

    public function setPassword($password, User $user, $flush = false) {
        $user->setPlainPassword($password);
        $encoder = $this->encoder_factory->getEncoder($user);
        $password = $encoder->encodePassword($password, $user->getSalt());
        $user->setPassword($password);

        if ($flush === true) {
            $this->transactional(function (EntityManagerInterface $em) use ($user) {
                $em->persist($user);
            });
        }
    }

    public function isPasswordValid(User $user, string $password): bool {
        $encoder = $this->encoder_factory->getEncoder($user);
        return $encoder->isPasswordValid($user->getPassword(), $password, $user->getSalt());
    }

    public function paswordRequest(User $user) {
        if ($user->isEnabled() === true) {
            $user->genToken();
            $user->setPasswordRequestedAt(new \DateTime());

            $this->transactional(function (EntityManagerInterface $em) use ($user) {
                $em->persist($user);
            });

            return true;
        }

        return false;
    }

    public function confirm($token) {
        $user = $this->UserByConfirmationToken($token);

        if ($user !== null) {
            $this->aid_man->CreateID($user, true);
            $this->loc_man->CreateLocation($user, true);

            $user->setToken('');
            $user->setEnabled(true);
            $user->enableClientsRegistration($this->defaultClientsRegistrationTime);
            $user->enableIoDevicesRegistration($this->defaultClientsRegistrationTime);

            $this->transactional(function (EntityManagerInterface $em) use ($user) {
                $em->persist($user);
            });
            return $user;
        }

        return null;
    }

    public function userByEmail($email) {
        return $this->rep->findOneByEmail($email);
    }

    public function userByConfirmationToken($token) {
        if ($token === null || strlen($token) < 40) {
            return null;
        }
        return $this->rep->findOneBy([
            'token' => $token,
            'enabled' => 0,
            'passwordRequestedAt' => null,
        ]);
    }

    public function userByPasswordToken($token) {
        if ($token === null || strlen($token) < 40) {
            return null;
        }

        $date = new \DateTime();
        $date->setTimeZone(new \DateTimeZone('UTC'));
        $date->sub(new \DateInterval('PT1H'));

        $qb = $this->rep->createQueryBuilder('u');

        try {
            return $qb->where($qb->expr()->eq('u.token', ':token'))
                ->andWhere("u.token != ''")
                ->andWhere("u.token IS NOT NULL")
                ->andWhere("u.enabled = 1")
                ->andWhere($qb->expr()->gte('u.passwordRequestedAt', ':date'))
                ->setParameter('token', $token)
                ->setParameter('date', $date)
                ->getQuery()
                ->getSingleResult();
        } catch (NoResultException $e) {
            return null;
        }
    }

    public function updateTimeZone(User $user, \DateTimeZone $timezone) {
        $currentTimezone = new \DateTimeZone($user->getTimezone());
        $user->setTimezone($timezone->getName());
        $this->transactional(function (EntityManagerInterface $em) use ($timezone, $currentTimezone, $user) {
            $em->persist($user);
            $now = new \DateTime();
            if ($currentTimezone->getOffset($now) != $timezone->getOffset($now)) {
                foreach ($user->getSchedules() as $schedule) {
                    /** @var Schedule $schedule */
                    if ($schedule->getEnabled()) {
                        $this->scheduleManager->recalculateScheduledExecutions($schedule);
                    }
                }
            }
        });
    }
}
