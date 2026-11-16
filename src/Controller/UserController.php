<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\User\CreateUserRequest;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/users', name: 'user_')]
final class UserController extends AbstractController
{
    public function __construct(
        private UserService $userService,
    ) {}

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->userService->getUserById($id);

        if ($user === null) {
            throw $this->createNotFoundException(sprintf('User #%d not found.', $id));
        }

        return $this->json([
            'id'        => $user->getId(),
            'name'      => $user->getName(),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function store(#[MapRequestPayload] CreateUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request);

        return $this->json([
            'id'        => $user->getId(),
            'name'      => $user->getName(),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }
}
