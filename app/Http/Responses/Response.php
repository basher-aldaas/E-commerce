<?php

namespace App\Http\Responses;

use App\Constants\HttpStatusConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class Response
{
    public static function Success($data,$message,$code=HttpStatusConstants::HTTP_200_OK) : JsonResponse
    {
        $payload = [
            'status'=>1,
            'data'=>$data,
            'message'=>$message,
            'status_code' => $code,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $payload['pagination'] = [
                'current_page' => $data->resource->currentPage(),
                'per_page' => $data->resource->perPage(),
                'total' => $data->resource->total(),
                'last_page' => $data->resource->lastPage(),
            ];
        }

        return response()->json($payload,$code);
    }

    public static function Error($data,$message,$code=HttpStatusConstants::HTTP_500_INTERNAL_SERVER_ERROR) : JsonResponse
    {
        return response()->json([
            'status'=>0,
            'data'=>$data,
            'message'=>$message,
            'status_code' => $code,
            'timestamp' => now()->toIso8601String(),
        ],$code);
    }

    public static function Validation($data,$message,$code=HttpStatusConstants::HTTP_422_UNPROCESSABLE_ENTITY) : JsonResponse
    {
        return response()->json([
            'status'=>0,
            'data'=>$data,
            'message'=>$message,
            'status_code' => $code,
            'timestamp' => now()->toIso8601String(),
        ],$code);
    }

}
