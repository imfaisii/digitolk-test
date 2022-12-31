<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('store jobs');
    }

    public function rules()
    {
        return [
            // job store validation rules e.g
            'by_admin' => ['required', 'in:yes,no']
        ];
    }
}
