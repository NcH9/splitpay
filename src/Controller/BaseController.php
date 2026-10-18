<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

abstract class BaseController extends AbstractController
{
    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected SerializerInterface $serializer,
        protected ValidatorInterface $validator,
    ) {
    }

    abstract protected function getEntityClass(): string;
    #[Route('', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $entity = $this->serializer->deserialize(
            $request->getContent(),
            $this->getEntityClass(),
            'json'
        );

        $this->validateEntity($entity);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->json($entity, 201);
    }
    #[Route('/{id}', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id,
        Request $request
    ): JsonResponse {
        $entity = $this->findEntity($id);

        $this->serializer->deserialize(
            $request->getContent(),
            $this->getEntityClass(),
            'json',
            ['object_to_populate' => $entity]
        );

        $this->validateEntity($entity);

        $this->entityManager->flush();

        return $this->json($entity);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->findEntity($id));
    }

    protected function findEntity(int $id): object
    {
        $entity = $this->entityManager
            ->getRepository($this->getEntityClass())
            ->find($id);

        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        return $entity;
    }

    protected function validateEntity(object $entity): void
    {
        $errors = $this->validator->validate($entity);

        if (count($errors) > 0) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException((string) $errors);
        }
    }
}