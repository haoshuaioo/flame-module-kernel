<?php

namespace Flame;

use think\App;
use think\exception\HttpResponseException;
use think\Request;
use think\Response;

/**
 * 模块控制器基础类
 */
abstract class BaseApiController extends BaseService
{
    /** @var string 默认响应类型，支持：json,jsonp,xml */
    protected string $responseType = 'json';

    public function __construct(
        App               $app,
        protected Request $request
    )
    {
        parent::__construct($app);
    }

    protected function success($data = null, string $msg = '', int $statusCode = 200, ?string $type = null, array $header = [], array $options = []): void
    {
        $this->result($data, $msg, 0, $statusCode, $type, $header, $options);
    }

    protected function error($data = null, string $msg = '', int $statusCode = 200, ?string $type = null, array $header = [], array $options = []): void
    {
        $this->result($data, $msg, 1, $statusCode, $type, $header, $options);
    }

    protected function result($data = null, string $msg = '', int $code = 0, int $statusCode = 200, ?string $type = null, array $header = [], array $options = []): void
    {
        self::response($data, $msg, $code, $statusCode, $type ?: $this->responseType, $header, $options);
    }

    static public function response($data = null, string $msg = '', int $code = 0, int $statusCode = 200, ?string $type = null, array $header = [], array $options = []): void
    {
        $type = $type ?: 'json';
        $result = [
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
            'time' => time()
        ];
        $resp = Response::create($result, $type, $statusCode)->header($header)->options($options);
        throw new HttpResponseException($resp);
    }
}