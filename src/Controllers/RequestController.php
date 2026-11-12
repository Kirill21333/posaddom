<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\PhotoService;
use App\Services\RequestService;

final class RequestController
{
    public static function list(Request $r): array
    {
        return RequestService::list($r->query, Auth::requireLogin());
    }

    public static function get(Request $r, array $p): array
    {
        return RequestService::card((int)$p['id'], Auth::requireLogin());
    }

    public static function preview(Request $r): array
    {
        return RequestService::preview($r->body);
    }

    public static function create(Request $r): array
    {
        return RequestService::create($r->body, Auth::requireLogin());
    }

    public static function update(Request $r, array $p): array
    {
        return RequestService::update((int)$p['id'], $r->body, Auth::requireLogin());
    }

    public static function status(Request $r, array $p): array
    {
        $to = (string)$r->str('status', '');
        if ($to === '') {
            throw HttpException::bad('Укажите новый статус', ['status' => 'Обязательное поле']);
        }
        return RequestService::changeStatus((int)$p['id'], $to, $r->str('comment'), Auth::requireLogin());
    }

    public static function assign(Request $r, array $p): array
    {
        $eid = $r->input('employee_id');
        return RequestService::assign((int)$p['id'], ($eid === null || $eid === '') ? null : (int)$eid, $r->str('reason'), Auth::requireLogin());
    }

    public static function candidates(Request $r, array $p): array
    {
        return RequestService::candidates((int)$p['id'], Auth::requireLogin());
    }

    public static function comment(Request $r, array $p): array
    {
        return RequestService::addComment((int)$p['id'], (string)$r->str('text', ''), $r->bool('is_additional_contact'), Auth::requireLogin());
    }

    public static function uploadPhoto(Request $r, array $p): array
    {
        return PhotoService::upload((int)$p['id'], $r->files['file'] ?? null, (string)$r->str('stage', 'initial'), Auth::requireLogin());
    }

    public static function photo(Request $r, array $p)
    {
        return PhotoService::download((int)$p['id'], Auth::requireLogin());
    }

    public static function deletePhoto(Request $r, array $p): array
    {
        PhotoService::delete((int)$p['id'], Auth::requireLogin());
        return ['deleted' => true];
    }
}
