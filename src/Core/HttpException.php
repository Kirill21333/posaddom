<?php
declare(strict_types=1);

namespace App\Core;

final class HttpException extends \RuntimeException
{
    public array $fields;
    /** Дополнительные ключи ответа (например, duplicate для 409 при дубле клиента). */
    public array $extra = [];
    public ?string $errorCode = null;

    public function __construct(int $status, string $message, array $fields = [])
    {
        parent::__construct($message, $status);
        $this->fields = $fields;
    }

    public function with(array $extra, ?string $code = null): self
    {
        $this->extra = $extra;
        $this->errorCode = $code;
        return $this;
    }

    public static function bad(string $m, array $fields = []): self { return new self(422, $m, $fields); }
    public static function forbidden(string $m = 'Недостаточно прав'): self { return new self(403, $m); }
    public static function notFound(string $m = 'Не найдено'): self { return new self(404, $m); }
    public static function conflict(string $m): self { return new self(409, $m); }
    public static function unauth(string $m = 'Требуется вход в систему'): self { return new self(401, $m); }
}
