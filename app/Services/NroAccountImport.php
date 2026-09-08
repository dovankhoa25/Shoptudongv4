<?php
namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class NroAccountImport
{
    public function __construct(private NroAccountRegistration $registration) {}

    private function resolve(Collection $rows, string $value, array $names, string $label): object
    {
        $matches = ctype_digit($value) ? $rows->where('id', (int) $value) : $rows->filter(fn ($row) => collect($names)->contains(fn ($name) => Str::lower(trim((string) ($row->$name ?? ''))) === Str::lower($value)));
        NroShopService::require($value !== '' && $matches->count() === 1, "$label không tồn tại, không được phép dùng hoặc tên bị trùng. Hãy nhập ID.");
        return $matches->first();
    }

    public function run(User $user, string $text, bool $commit): array
    {
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $text));
        NroShopService::require(count(array_filter($lines, fn ($line) => trim($line) !== '')) <= 200, 'Mỗi lần nhập tối đa 200 acc.');
        $servers = DB::table('servers')->where('status', true)->get(['id', 'name', 'name_view']);
        $loginServers = DB::table('server_game_login')->get(['id', 'name']);
        $categories = ($user->canViewAllAdminData() ? Category::query() : $user->categories()->wherePivot('can_post', true))
            ->where('template', 'default')->where('status', 'active')->get(['categories.id', 'categories.name', 'categories.slug']);
        $rows = []; $seen = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') continue;
            $row = ['line' => $index + 1, 'status' => 'invalid', 'message' => ''];
            try {
                $parts = str_getcsv($line, '|', '"', '');
                NroShopService::require(count($parts) >= 5 && count($parts) <= 9, 'Cần 5–9 cột ngăn bằng | theo mẫu.');
                $parts = array_pad($parts, 9, '');
                [$username, $password, $svView, $svLogin, $type, $category, $price, $description, $imageUrl] = $parts;
                // Password is intentionally not trimmed, included in preview, logs or error messages.
                $username = trim($username); $type = trim($type);
                NroShopService::require(in_array($type, ['0', '1'], true), 'Loại acc phải là 0 (bán nick) hoặc 1 (kho đồ).');
                $server = $this->resolve($servers, trim($svView), ['name_view', 'name'], 'Server hiển thị');
                $login = $this->resolve($loginServers, trim($svLogin), ['name'], 'Server đăng nhập');
                $input = ['username' => $username, 'password' => $password, 'serverId' => $server->id, 'serverGameId' => $login->id, 'usageType' => $type === '0' ? 'nick' : 'warehouse'];
                if ($type === '0') {
                    $cat = $this->resolve($categories, trim($category), ['name', 'slug'], 'Danh mục');
                    $input += ['categoryId' => $cat->id, 'price' => trim($price), 'description' => $description, 'imageUrl' => trim($imageUrl) ?: null];
                    $row['categoryName'] = $cat->name;
                }
                $v = $this->registration->validate($user, $input);
                $key = Str::lower($username).'|'.$login->id;
                NroShopService::require(!isset($seen[$key]), 'Acc và server bị lặp trong danh sách này.');
                DB::transaction(fn () => $this->registration->available($username, $login->id));
                $seen[$key] = true;
                $row += ['username' => $username, 'serverName' => $server->name_view ?: $server->name, 'loginServerName' => $login->name, 'usageType' => $input['usageType'], 'price' => isset($v['price']) ? (int) $v['price'] : null];
                if ($commit) {
                    $account = $this->registration->create($user, $input);
                    $row['accountId'] = $account->id;
                }
                $row['status'] = $commit ? 'created' : 'valid';
                $row['message'] = $commit ? 'Đã thêm, đang chờ tool lấy dữ liệu.' : 'Hợp lệ';
            } catch (ValidationException $e) {
                $row['message'] = collect($e->errors())->flatten()->implode(' ');
            } catch (HttpExceptionInterface $e) {
                $row['message'] = 'Không có quyền thực hiện hoặc dữ liệu không còn tồn tại.';
            } catch (\Throwable $e) {
                // Do not log exceptions containing SQL bindings/credentials from imported rows.
                $row['message'] = 'Không thể lưu dòng này. Kiểm tra lại dữ liệu và thử lại.';
            }
            $rows[] = $row;
        }
        NroShopService::require(count($rows) > 0, 'Danh sách đang trống.');
        return ['rows' => $rows, 'valid' => count(array_filter($rows, fn ($r) => in_array($r['status'], ['valid', 'created']))), 'invalid' => count(array_filter($rows, fn ($r) => $r['status'] === 'invalid'))];
    }
}
