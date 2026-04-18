<?php

namespace App\Service;

use App\Entity\User;
use App\Exception\DuplicateUserException;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

class UserService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $repository
    ) {}

    public function createUser(string $name, string $email): User
    {
        $name = trim($name);
        $email = trim($email);

        if ($name === '') {
            throw new \InvalidArgumentException('name is required');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('email is required and must be valid');
        }

        if ($this->repository->findOneByEmail($email) !== null) {
            throw new DuplicateUserException('A user with this email address already exists');
        }

        $user = new User();
        $user->setName($name)
            ->setEmail($email);

        $this->em->persist($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateUserException('A user with this email address already exists', 0, $e);
        }

        return $user;
    }

    public function getUser(int $id): User
    {
        $user = $this->repository->find($id);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        return $user;
    }
}
