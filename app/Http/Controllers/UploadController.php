<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use App\Http\Requests;
use Image;
use Illuminate\Support\Facades\Response;

class UploadController extends Controller
{
    public function index() {
    	
		if (Input::file ( 'icon' )) {
			$image = Input::file ( 'icon' );
			$filename = date ('YmdHis') . uniqid(rand(0,99999)) . '.' . $image->getClientOriginalExtension ();
			$path = public_path ( 'icons/' . $filename );
			Image::make ( $image->getRealPath () )->resize ( 200, 200 )->save ( $path );
			return Response::json ( [ 
					'filename' => 'icons/' . $filename 
			] );
	    } else {
			throw new \TypeError ( "Missing file to upload" );
		}
    }
    
}
