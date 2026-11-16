<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\DTO\User\CreateUserRequest;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WebUserController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    #[Route('/web/users', name: 'web_user_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $name = trim((string) $request->request->get('name'));

        if ($name === '') {
            $this->addFlash('error', 'Ім\'я користувача не може бути порожнім.');
            return $this->redirect($request->headers->get('referer', $this->generateUrl('web_dashboard')));
        }

        try {
            $user = $this->userService->create(new CreateUserRequest($name));
            $this->addFlash('success', sprintf('Користувача <strong>%s</strong> (ID: #%d) успішно створено.', htmlspecialchars($user->getName(), ENT_QUOTES, 'UTF-8'), $user->getId()));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Помилка під час створення користувача: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $this->redirect($request->headers->get('referer', $this->generateUrl('web_dashboard')));
    }
}
