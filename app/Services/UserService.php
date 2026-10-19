<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;

/**
 * FN-01.03 Quản lý tài khoản người dùng (STEP 15 - quyết định V1-V4, docs mục 14.2).
 * - Không xoá cứng tài khoản (V1): chỉ đổi trạng thái hoat_dong <-> ngung, mở khoá khoa_tam.
 * - Tự bảo vệ (V2): không đổi vai trò / trạng thái của chính mình; luôn còn >= 1 BQL hoạt động
 *   (khoá FOR UPDATE tập BQL hoạt động TRƯỚC khi khoá dòng đích - thứ tự khoá cố định).
 * - Mật khẩu (V4): chỉ lưu password_hash; không ghi vào audit / log / HTML; tham số #[\SensitiveParameter].
 * - Mọi sửa có version token (gồm vân tay mật khẩu) -> 2 người sửa cùng lúc: người sau bị từ chối.
 */
class UserService extends BaseService
{
    /** [ASSUMPTION] tài liệu không quy định định dạng username (J-03 liên quan). */
    private const USERNAME_REGEX = '/^[A-Za-z0-9][A-Za-z0-9._-]{2,49}$/';
    public const STATUS_ACTIONS = ['deactivate' => 'Vô hiệu hóa', 'activate' => 'Kích hoạt lại', 'unlock' => 'Mở khóa'];

    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    public function search(array $filters, int $page): array
    {
        return $this->users->search($filters, $page, (int) config('master_data.per_page', 20));
    }

    public function find(int $id): ?array
    {
        $user = $this->users->findPublic($id);
        if ($user === null) {
            return null;
        }
        $user['version_token'] = $this->versionToken($this->users->find($id));
        $user['is_self'] = $id === $this->actorId();

        return $user;
    }

    /** Hoạt động gần nhất liên quan tới tài khoản (đối tượng users, id này) - chỉ đọc. */
    public function recentAudit(int $id): array
    {
        return (new AuditLogRepository())->search(['entity_type' => 'users', 'entity_id' => (string) $id], 1, 20)['data'];
    }

    /** @param array{username:string,full_name:string,role:string} $input */
    public function create(array $input, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirm): int
    {
        $v = (new Validator($input))
            ->required('username', 'Tên đăng nhập là bắt buộc.')
            ->pattern('username', self::USERNAME_REGEX, 'Tên đăng nhập 3–50 ký tự, gồm chữ, số và . _ - (không dấu cách, bắt đầu bằng chữ/số).')
            ->required('full_name', 'Họ tên là bắt buộc.')->maxLength('full_name', 150, 'Họ tên tối đa 150 ký tự.')
            ->required('role', 'Vai trò là bắt buộc.')->in('role', array_keys(config('master_data.user_role')), 'Vai trò không hợp lệ.');
        $this->checkPassword($v, $password, $confirm);
        $v->throwIfFails();

        return $this->transaction($this->users, function () use ($input, $password): int {
            $row = ['username' => $input['username'], 'full_name' => $input['full_name'], 'role' => $input['role'], 'status' => 'hoat_dong'];
            $id = $this->users->insertUnlessDuplicate($row + ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_by' => $this->actorId()]);
            if ($id === null) {
                throw ValidationException::withMessage('Tên đăng nhập đã tồn tại.', 'username');
            }
            $this->audit('CREATE_USER', 'users', $id, null, $row); // không có mật khẩu / hash

            return $id;
        });
    }

    /** Sửa họ tên, vai trò. Username không sửa (định danh đăng nhập + dấu vết audit). */
    public function update(int $id, array $input, string $token): void
    {
        (new Validator($input))
            ->required('full_name', 'Họ tên là bắt buộc.')->maxLength('full_name', 150, 'Họ tên tối đa 150 ký tự.')
            ->required('role', 'Vai trò là bắt buộc.')->in('role', array_keys(config('master_data.user_role')), 'Vai trò không hợp lệ.')
            ->throwIfFails();

        $this->transaction($this->users, function () use ($id, $input, $token): void {
            $activeBql = $this->users->lockActiveBqlIds();
            $user = $this->lockTarget($id, $token);
            $changes = $this->diff($user, ['full_name' => $input['full_name'], 'role' => $input['role']]);
            if ($changes === []) {
                throw ValidationException::withMessage('Không có thay đổi nào.');
            }
            if (isset($changes['role'])) {
                if ($id === $this->actorId()) {
                    throw ValidationException::withMessage('Không được tự đổi vai trò của chính mình.', 'role');
                }
                $this->assertBqlRemains($activeBql, $id);
            }
            $this->users->updateById($id, ['full_name' => $input['full_name'], 'role' => $input['role']]);
            $this->audit('UPDATE_USER', 'users', $id, array_intersect_key($user, $changes), $changes);
        });
    }

    /** deactivate: hoat_dong|khoa_tam -> ngung; activate: ngung -> hoat_dong; unlock: khoa_tam -> hoat_dong. */
    public function changeStatus(int $id, string $action, string $token): string
    {
        if (!array_key_exists($action, self::STATUS_ACTIONS)) {
            throw ValidationException::withMessage('Thao tác trạng thái không hợp lệ.');
        }
        if ($id === $this->actorId()) {
            throw ValidationException::withMessage('Không được tự thay đổi trạng thái tài khoản của chính mình.');
        }

        return $this->transaction($this->users, function () use ($id, $action, $token): string {
            $activeBql = $this->users->lockActiveBqlIds();
            $user = $this->lockTarget($id, $token);
            $allowed = ['deactivate' => ['hoat_dong', 'khoa_tam'], 'activate' => ['ngung'], 'unlock' => ['khoa_tam']][$action];
            if (!in_array($user['status'], $allowed, true)) {
                throw ValidationException::withMessage('Không thể "' . self::STATUS_ACTIONS[$action] . '" tài khoản đang ở trạng thái ' . label_source('user_status', $user['status']) . '.');
            }
            if ($action === 'deactivate') {
                $this->assertBqlRemains($activeBql, $id);
                $new = ['status' => 'ngung'];
            } else {
                $new = ['status' => 'hoat_dong', 'failed_login_count' => 0, 'locked_until' => null];
            }
            $this->users->updateById($id, $new);
            $this->audit('CHANGE_USER_STATUS', 'users', $id,
                ['status' => $user['status'], 'failed_login_count' => (int) $user['failed_login_count'], 'locked_until' => $user['locked_until']],
                $new + ['action' => $action]);

            return $new['status'];
        });
    }

    /** Đặt lại mật khẩu: mọi phiên đang mở của tài khoản bị thu hồi ở request kế tiếp (vân tay đổi - V5). */
    public function resetPassword(int $id, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirm, string $token): void
    {
        $v = new Validator([]);
        $this->checkPassword($v, $password, $confirm);
        $v->throwIfFails();

        $hash = $this->transaction($this->users, function () use ($id, $password, $token): string {
            $user = $this->lockTarget($id, $token);
            if (password_verify($password, $user['password_hash'])) {
                throw ValidationException::withMessage('Mật khẩu mới phải khác mật khẩu hiện tại.', 'password');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->users->updateById($id, ['password_hash' => $hash, 'failed_login_count' => 0]);
            $this->audit('RESET_USER_PASSWORD', 'users', $id, null, ['password_reset' => true, 'other_logins_revoked' => true]);

            return $hash;
        });
        if ($id === $this->actorId()) {
            Session::put('auth_fp', AuthMiddleware::fingerprint($hash)); // tự đặt lại: giữ phiên hiện tại
        }
    }

    /** Token chống ghi đè: đổi khi bất kỳ trường nào (kể cả mật khẩu) thay đổi. */
    public function versionToken(array $user): string
    {
        return sha1(implode('|', [$user['id'], $user['username'], $user['full_name'], $user['role'], $user['status'],
            $user['failed_login_count'], (string) $user['locked_until'], $user['updated_at'], sha1($user['password_hash'])]));
    }

    private function lockTarget(int $id, string $token): array
    {
        $user = $this->users->findForUpdate($id);
        if ($user === null) {
            throw ValidationException::withMessage('Tài khoản không tồn tại.');
        }
        if (!hash_equals($this->versionToken($user), $token)) {
            throw ValidationException::withMessage('Tài khoản đã bị người khác thay đổi sau khi bạn mở trang. Vui lòng tải lại trang.');
        }

        return $user;
    }

    /** V2: sau thao tác phải còn ít nhất 1 BQL hoạt động. */
    private function assertBqlRemains(array $activeBqlIds, int $targetId): void
    {
        if (in_array($targetId, $activeBqlIds, true) && count($activeBqlIds) <= 1) {
            throw ValidationException::withMessage('Không thể thực hiện: hệ thống phải còn ít nhất một tài khoản BQL đang hoạt động.');
        }
    }

    /** [TL] NFR-01: >= 8 ký tự, có chữ hoa và số. <= 72 byte: giới hạn của bcrypt [ASSUMPTION]. */
    private function checkPassword(Validator $v, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirm): void
    {
        if ($password === '') {
            $v->addError('password', 'Mật khẩu là bắt buộc.');

            return;
        }
        if (mb_strlen($password) < 8 || !preg_match('/\p{Lu}/u', $password) || !preg_match('/\d/', $password)) {
            $v->addError('password', 'Mật khẩu tối thiểu 8 ký tự, có ít nhất 1 chữ hoa và 1 chữ số.');
        } elseif (strlen($password) > 72 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            $v->addError('password', 'Mật khẩu tối đa 72 byte và không chứa ký tự điều khiển.');
        }
        if (!hash_equals($password, $confirm)) {
            $v->addError('password_confirmation', 'Xác nhận mật khẩu không khớp.');
        }
    }
}
