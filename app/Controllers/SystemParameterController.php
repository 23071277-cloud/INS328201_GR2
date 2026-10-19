<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\SystemParameterService;

/** FN-01.07 Cấu hình tham số hệ thống (STEP 15) - chỉ khoá whitelist; không thêm / xoá khoá, không sửa .env. */
class SystemParameterController extends BaseController
{
    private SystemParameterService $service;

    public function __construct()
    {
        $this->service = new SystemParameterService();
    }

    public function index(): void
    {
        $this->view('system_parameters.index', ['title' => t('page.system_parameters'), 'rows' => $this->service->list()]);
    }

    public function edit(string $id): void
    {
        $param = $this->service->find($this->routeId($id)) ?? $this->notFound();

        $this->view('system_parameters.form', ['title' => 'Sửa tham số ' . $param['key'], 'p' => $param, 'form' => Flash::pullFormState()]);
    }

    public function update(string $id): void
    {
        $paramId = $this->routeId($id);
        $this->service->find($paramId) ?? $this->notFound();
        $value = Request::postString('config_value');
        try {
            $this->service->update($paramId, $value, Request::postString('version_token'));
            Flash::success('Đã lưu tham số. Giá trị mới có hiệu lực từ request kế tiếp.');
            $this->redirect('/system-parameters');
        } catch (ValidationException $e) {
            $this->backWithErrors("/system-parameters/{$paramId}/edit", $e, ['config_value' => $value]);
        }
    }
}
