<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function create($request): User
    {
        $user = new User();

        $user->setUsername($request->username);
        $this->userRepository->save($user);

        return $user;
    }

    public function getUserById(int $id): ?User
    {
        return $this->userRepository->find($id);
    }
}