<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\CategoryResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategorieResource;
use App\Models\CategoryProduct;
use App\Models\SubCategoryProduct;
use Exception;
use Illuminate\Http\Request;

class CategorieProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        try{
            // Catalogue global : tous les restaurants confondus, mais les
            // produits sont precharges explicitement. La ressource ne les
            // charge plus paresseusement, et ce prechargement supprime au
            // passage un N+1 (une requete par sous-categorie).
            $categorie = CategoryProduct::with(['sub_category_product.product' => function ($query) {
                $query->where('is_active', true);
            }])->get();
            return CategorieResource::collection($categorie);
        }
        catch(Exception $e){
            return $e;
        }

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(CategoryProduct $categorieProduct)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CategoryProduct $categorieProduct)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CategoryProduct $categorieProduct)
    {
        //
    }
}
