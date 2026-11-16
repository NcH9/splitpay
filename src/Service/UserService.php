<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\User\CreateUserRequest;
use App\Entity\User;
use App\Repository\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    public function create(CreateUserRequest $request): User
    {
        $user = new User($request->name);
        $this->userRepository->save($user);

        return $user;
    }

    public function getUserById(int $id): ?User
    {
        return $this->userRepository->find($id);
    }
}
