<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Raw;
use App\Core\Settings;

/** Фотографии заявок: загрузка с проверкой типа по содержимому, хранение, выдача с проверкой прав (правило 8). */
final class PhotoService
{
    public static function upload(int $requestId, ?array $file, string $stage, array $user): array
    {
        $r = RequestService::accessible($requestId, $user);
        if (!in_array('photo', RequestService::allowedActions($r, $user), true)) {
            throw in_array($r['status_code'], ['closed', 'rejected', 'cancelled'], true)
                ? HttpException::conflict('К заявке в конечном статусе нельзя добавлять фотографии')
                : HttpException::forbidden('Загрузка фото недоступна');
        }
        if (!in_array($stage, ['initial', 'final'], true)) {
            throw HttpException::bad('Этап фото: initial («до») или final («после»)', ['stage' => 'Недопустимое значение']);
        }
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw HttpException::bad('Выберите файл фотографии', ['file' => 'Файл не передан']);
        }
        $maxMb = max(1, Settings::int('photo_max_mb', 10));
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > $maxMb * 1048576) {
            throw HttpException::bad("Размер файла не более $maxMb МБ", ['file' => 'Файл слишком большой']);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw HttpException::bad('Не удалось загрузить файл', ['file' => 'Ошибка загрузки']);
        }
        $ext = self::detectExtension($file['tmp_name']);
        if ($ext === null) {
            throw HttpException::bad('Допустимы только изображения JPEG и PNG', ['file' => 'Недопустимый тип файла']);
        }
        $year = date('Y');
        $dir = "$year/$requestId";
        if (!is_dir(STORAGE . "/photos/$dir")) {
            mkdir(STORAGE . "/photos/$dir", 0775, true);
        }
        $stored = $dir . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], STORAGE . '/photos/' . $stored)) {
            throw new HttpException(500, 'Не удалось сохранить файл');
        }
        $years = max(1, Settings::int('photo_retention_years', 3));
        $name = self::cleanName((string)($file['name'] ?? ''), $ext);
        $id = Db::insert('request_photos', [
            'request_id' => $requestId, 'uploaded_by' => $user['id'], 'stage' => $stage, 'file_name' => $name,
            'file_path' => $stored, 'file_size' => (int)filesize(STORAGE . '/photos/' . $stored), 'uploaded_at' => now(),
            'retention_until' => date('Y-m-d', strtotime("+$years years")),
        ]);
        Log::audit('request.photo', 'request', $requestId, null, ['photo_id' => $id, 'stage' => $stage], $user['id']);
        return ['id' => $id, 'stage' => $stage, 'file_name' => $name, 'url' => '/api/photos/' . $id,
                'uploaded_by_name' => $user['short_name'], 'uploaded_at' => now(), 'retention_until' => date('Y-m-d', strtotime("+$years years"))];
    }

    /** Файл фото для выдачи: права проверяются по заявке. */
    public static function download(int $photoId, array $user): Raw
    {
        $p = Db::one('SELECT * FROM request_photos WHERE id = ?', [$photoId]);
        if (!$p) {
            throw HttpException::notFound('Фото не найдено');
        }
        RequestService::accessible((int)$p['request_id'], $user);
        $path = STORAGE . '/photos/' . $p['file_path'];
        if (!is_file($path)) {
            throw HttpException::notFound('Файл фото отсутствует на сервере');
        }
        $mime = self::detectExtension($path) === 'png' ? 'image/png' : 'image/jpeg';
        return new Raw($mime, '', null, $path);
    }

    /** Удаление раньше окончания срока хранения запрещено (и триггером БД). */
    public static function delete(int $photoId, array $user): void
    {
        $p = Db::one('SELECT * FROM request_photos WHERE id = ?', [$photoId]);
        if (!$p) {
            throw HttpException::notFound('Фото не найдено');
        }
        if ($p['retention_until'] > date('Y-m-d')) {
            throw HttpException::conflict('Фото нельзя удалить раньше окончания срока хранения (до ' . date('d.m.Y', strtotime($p['retention_until'])) . ')');
        }
        $st = Db::val('SELECT s.code FROM requests r JOIN request_statuses s ON s.id = r.status_id WHERE r.id = ?', [(int)$p['request_id']]);
        if ($st !== 'closed') {
            throw HttpException::conflict('Фото незакрытой заявки не удаляются');
        }
        Db::exec('DELETE FROM request_photos WHERE id = ?', [$photoId]);
        @unlink(STORAGE . '/photos/' . $p['file_path']);
        Log::audit('photo.delete', 'request', (int)$p['request_id'], ['photo_id' => $photoId], null, $user['id']);
    }

    /** Расширение по сигнатуре файла: jpg / png либо null. Расширению и Content-Type из запроса не доверяем. */
    public static function detectExtension(string $path): ?string
    {
        $h = @file_get_contents($path, false, null, 0, 12);
        if ($h === false || strlen($h) < 8) {
            return null;
        }
        if (substr($h, 0, 3) === "\xFF\xD8\xFF") {
            $ext = 'jpg';
        } elseif (substr($h, 0, 8) === "\x89PNG\r\n\x1a\n") {
            $ext = 'png';
        } else {
            return null;
        }
        $info = @getimagesize($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }
        return $ext;
    }

    private static function cleanName(string $name, string $ext): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $base) ?: 'photo';
        return mb_substr($base, 0, 100) . '.' . $ext;
    }
}
