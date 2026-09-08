<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

use think\facade\Db;
use think\file\UploadedFile;

/** 财务图像保存在站点公开目录之外；所有读取重新经过业务授权。 */
final class FinanceEvidence
{
    public static function save(string $type, ?UploadedFile $file): array
    {
        FinanceDocumentPolicy::authorize($type);
        if (!$file || !$file->isValid() || $file->getSize() <= 0 || $file->getSize() > 5 * 1024 * 1024) {
            throw new \DomainException('请选择不超过5MB的核验截图');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$extension || !getimagesize($file->getPathname())) { throw new \DomainException('截图只支持有效的 PNG、JPEG 或 WebP 图片'); }
        $tenantId = FinanceAccess::tenant(); $key = bin2hex(random_bytes(24)) . '.' . $extension;
        $snapshot = ['storage_key' => $key, 'name' => FinanceValue::text($file->getOriginalName(), 180), 'mime' => $mime, 'size' => $file->getSize()];
        $target = $file->move(self::directory($tenantId), $key);
        try {
            $id = (int)Db::name('finance_evidence')->insertGetId(['tenant_id' => $tenantId, 'document_type' => $type, 'file_id' => 0,
                'snapshot' => FinanceValue::json($snapshot), 'created_by' => FinanceValue::json(FinanceAccess::actor()), 'create_time' => time()]);
        } catch (\Throwable $error) { unlink($target->getPathname()); throw $error; }
        return ['tenant_id' => $tenantId, 'id' => $id, 'file' => self::metadata(['id' => $id, 'snapshot' => FinanceValue::json($snapshot)])];
    }

    public static function metadata(array $row): array
    {
        $snapshot = FinanceValue::decode($row['snapshot']);
        return ['id' => (int)$row['id'], 'name' => $snapshot['name'] ?? '核验截图', 'mime' => $snapshot['mime'] ?? '', 'size' => $snapshot['size'] ?? 0];
    }

    public static function content(int $id): array
    {
        if (FinanceAccess::tenant() <= 0 || FinanceAccess::operator() <= 0) { throw new \DomainException('请先登录并选择门店'); }
        $row = Db::name('finance_evidence')->where('tenant_id', FinanceAccess::tenant())->where('id', $id)->find();
        if (!$row) { throw new \DomainException('截图不存在或不属于本门店'); }
        if (in_array($row['document_type'], ['salary_expense', 'salary_payment', 'salary_adjustment'], true)) { FinanceDocumentPolicy::read($row['document_type']); }
        else { FinanceDocumentPolicy::authorize($row['document_type']); }
        $snapshot = FinanceValue::decode($row['snapshot']);
        $key = $snapshot['storage_key'] ?? '';
        if (!preg_match('/^[a-f0-9]{48}\.(png|jpg|webp)$/D', $key)) { throw new \DomainException('截图存储资料无效'); }
        $path = self::directory(FinanceAccess::tenant()) . $key;
        if (!is_file($path)) { throw new \DomainException('截图文件暂不可用，请联系管理员核查存储'); }
        return ['tenant_id' => FinanceAccess::tenant(), 'id' => $id, 'mime' => $snapshot['mime'], 'base64' => base64_encode(file_get_contents($path))];
    }

    private static function directory(int $tenantId): string { return root_path() . 'storage/finance-evidence/' . $tenantId . '/'; }
}
