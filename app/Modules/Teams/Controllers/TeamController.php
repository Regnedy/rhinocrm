<?php

namespace App\Modules\Teams\Controllers;

use App\Modules\Teams\Requests\AddTeamMemberRequest;
use App\Modules\Teams\Requests\CreateTeamRequest;
use App\Modules\Teams\Requests\SetTeamGoalRequest;
use App\Modules\Teams\Requests\UpdateTeamRequest;
use App\Modules\Teams\Services\TeamService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeamController
{
    use ApiResponse;

    public function index(TeamService $service): JsonResponse { return $this->successResponse($service->list()); }
    public function store(CreateTeamRequest $request, TeamService $service): JsonResponse { return $this->successResponse($service->create($request->validated()), 'Equipo creado.', 201); }
    public function show(string $id, TeamService $service): JsonResponse { return $this->successResponse($service->show($id)); }
    public function update(string $id, UpdateTeamRequest $request, TeamService $service): JsonResponse { return $this->successResponse($service->update($id, $request->validated()), 'Equipo actualizado.'); }
    public function addMember(string $id, AddTeamMemberRequest $request, TeamService $service): JsonResponse
    {
        $data = $request->validated();
        return $this->successResponse($service->addMember($id, $data['user_id'], $data['member_role'] ?? 'MEMBER'), 'Integrante agregado.');
    }
    public function removeMember(string $id, string $userId, TeamService $service): JsonResponse { return $this->successResponse($service->removeMember($id, $userId), 'Integrante retirado.'); }
    public function setGoal(string $id, SetTeamGoalRequest $request, TeamService $service): JsonResponse { return $this->successResponse($service->setGoal($id, $request->validated()), 'Meta guardada.'); }
    public function report(string $id, Request $request, TeamService $service): JsonResponse { return $this->successResponse($service->report($id, (string) $request->query('period', date('Y-m')))); }
    public function summary(Request $request, TeamService $service): JsonResponse { return $this->successResponse($service->summary((string) $request->query('period', date('Y-m')))); }
}
