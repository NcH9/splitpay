<?php

namespace App\Controller;

use App\DTO\User\CreateUserRequest;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractController
{
    public function __construct(
        private UserService $userService
    ) {}

    #[Route('/user', name: 'app_user', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'message' => 'Welcome to your new controller!',
            'path' => 'src/Controller/UserController.php',
        ]);
    }

    public function get()
    {

    }

    #[Route('/user/{id}', name:'show_user', methods: ['GET'])]
    public function show(int $id)
    {
        $user = $this->userService->getUserById($id);

        return $this->json($user, 201);
    }

    #[Route('/user', name:'create_user', methods: ['POST'], format: 'json')]
    public function store(#[MapRequestPayload] CreateUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request);

        return $this->json([
            'message' => 'User created successfully!',
        ], 201);
    }
}
