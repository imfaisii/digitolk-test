<?php

namespace App\Traits;

use Exception;

trait Jsonify
{

    public static function success($data = [], $message = 'success', $code = 200)
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    public static function error($data = [], $message = 'error', $code = 400)
    {
        return response()->json([
            'code' => $code,
            'message' => $message instanceof Exception ? ($message->errorInfo[2] ?? $message->getMessage()) : $message,
            'data' => $data
        ], $code);
    }
}
