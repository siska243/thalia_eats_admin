<?php

namespace App\Wrappers;

use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponse
{
    public static function GET_DATA($data)
    {
        return response()->json(
            $data, 200);
    }

    public static function NOT_AUTHORIZED($title = 'Authentification requise', $message = 'Veuillez vous connecter'): JsonResponse
    {

        return response()->json([
            'title' => $title,
            'message' => $message,
        ], 401);

    }

    /**
     * Le nom porte une faute d'origine : il fait partie du contrat, on ne le
     * corrige pas.
     *
     * Les deux arguments sont facultatifs et par defaut rendent exactement la
     * reponse d'avant : aucun appelant existant ne change de comportement.
     * Ils evitent une seconde methode « FORBIDDEN » a une lettre pres, qu'on
     * confondrait a la lecture.
     */
    public static function FORBIDEN($title = 'Forbiden', $message = 'Vous etes pas authorizer')
    {
        throw new HttpResponseException(
            response()->json([
                'title' => $title,
                'message' => $message,
            ], 403)
        );
    }

    public static function BAD_REQUEST($errors, $title, $message): JsonResponse
    {

        return response()->json([
            'title' => $title,
            'message' => $message,
            'error' => $errors,
        ], 400);

    }

    public static function TOO_MANY_ATTEMPTS($title, $message): JsonResponse
    {

        return response()->json([
            'title' => $title,
            'message' => $message,
        ], 429);

    }

    public static function NOT_FOUND($title, $message)
    {

        return response()->json([
            'title' => $title,
            'message' => $message,
        ], 404);

    }

    public static function SUCCESS_DATA($data, $title = 'Crée', $message = 'La resource a été crée')
    {
        return response()->json([
            'data' => $data,
            'title' => $title,
            'message' => $message,
        ], 201);
    }

    public static function SERVER_ERROR(Exception $e)
    {

        throw new HttpResponseException(
            response()->json([
                'title' => 'Server error',
                'message' => $e->getMessage(),
            ], 500)
        );
    }
}
