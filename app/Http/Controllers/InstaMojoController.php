<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use DB;
use Auth;
use App\Cart;
use App\Wishlist;
use App\Order;
use App\Currency;
use Mail;
use App\Mail\SendOrderMail;
use App\Notifications\UserEnroll;
use App\Course;
use App\User;
use Notification;
use Carbon\Carbon;
use App\InstructorSetting;
use App\PendingPayout;

class InstaMojoController extends Controller
{
    public function index()
   	{
        return view('pages.checkout.show');
   	}

	public function pay(Request $request){
	 
	     $api = new \Instamojo\Instamojo(
	            config('services.instamojo.api_key'),
	            config('services.instamojo.auth_token'),
	            config('services.instamojo.url')
	        );

	    $appurl = env('APP_URL');
	    $appname = env('APP_NAME');
	 
	    try {
	         $response = $api->paymentRequestCreate(array(
	            "purpose" => $appname,
	            "amount" => $request->amount,
	            "buyer_name" => $request->name,
	            "send_email" => true,
	            "email" => $request->email,
	            "phone" => $request->mobile_number,
	            "redirect_url" => $appurl."/pay-success"
	            ));
	             
	            header('Location: ' . $response['longurl']);
	            exit();
	            
	    }catch (Exception $e) {
	        print('Error: ' . $e->getMessage());
	    }
	}
	 
	public function success(Request $request){
	    try {
	 
	        $api = new \Instamojo\Instamojo(
	            config('services.instamojo.api_key'),
	            config('services.instamojo.auth_token'),
	            config('services.instamojo.url')
	        );
	 
	         $response = $api->paymentRequestStatus(request('payment_request_id'));
	 
	        if( !isset($response['payments'][0]['status']) ) {
	           	
	           	\Session::flash('delete', 'Payment failed');
		    	return Redirect::route('home');

	        } else if($response['payments'][0]['status'] != 'Credit') {
	            
	            \Session::flash('delete', 'Payment failed');
		    	return Redirect::route('home');
	        } 
	        
	      	}catch (\Exception $e) {
	        	\Session::flash('delete', 'Payment failed');
		    	return Redirect::route('home');

	    }

	    $currency = Currency::first();
	    
	    $carts = Cart::where('user_id',Auth::User()->id)->get();
		   
	   	foreach($carts as $cart)
	   	{
	   		if ($cart->offer_price != 0)
            {
                $pay_amount =  $cart->offer_price;
            }
            else
            {
                $pay_amount =  $cart->price;
            } 

            if ($cart->disamount != 0 || $cart->disamount != NULL)
            {
                $cpn_discount =  $cart->disamount;
            }
            else
            {
                $cpn_discount =  '';
            }


            if ($cart->courses->duration != NULL && $cart->courses->duration !='')
            {
                $days = $cart->courses->duration * 30;
                $todayDate = date('Y-m-d');
                $expireDate = date("Y-m-d", strtotime("$todayDate +$days days"));
            }
            else{
                $todayDate = NULL;
                $expireDate = NULL;
            }

            $setting = InstructorSetting::first();

                if(isset($setting))
                {
                    if($cart->courses->user->role == "instructor")
                    {
                        $x_amount = $pay_amount * $setting->instructor_revenue;
                        $instructor_payout = $x_amount / 100;
                    }
                    else
                    {
                        $instructor_payout = NULL;
                    }
                }


                $lastOrder = Order::orderBy('created_at', 'desc')->first();

                if ( ! $lastOrder )
                {
                    // We get here if there is no order at all
                    // If there is no number set it to 0, which will be 1 at the end.
                    $number = 0;
                }
                else
                { 
                    $number = substr($lastOrder->order_id, 3);
                }
            
		    $created_order = Order::create([
	        	'course_id' => $cart->course_id,
	        	'user_id' => Auth::User()->id,
	        	'instructor_id' => $cart->courses->user_id,
	        	'order_id' => '#' . sprintf("%08d", intval($number) + 1),
	            'transaction_id' => $request->payment_id,
	            'payment_method' => 'Instamojo',
	            'total_amount' => $pay_amount,
	            'coupon_discount' => $cpn_discount,
	            'currency' => $currency->currency,
	            'currency_icon' => $currency->icon,
	            'duration' => $cart->courses->duration,
	            'enroll_start' => $todayDate,
                'enroll_expire' => $expireDate,
	            'created_at'  => \Carbon\Carbon::now()->toDateTimeString(),
	            ]
	        );

	        Wishlist::where('user_id',Auth::User()->id)->where('course_id', $cart->course_id)->delete();

	        Cart::where('user_id',Auth::User()->id)->where('course_id', $cart->course_id)->delete();

	        if($created_order){

                    if($cart->courses->user->role == "instructor")
                    {

                        $created_payout = PendingPayout::create([
                            'user_id' => $cart->courses->user_id,
                            'course_id' => $cart->course_id,
                            'order_id' => $created_order->id,
                            'transaction_id' => $request->payment_id,
                            'total_amount' => $pay_amount,
                            'currency' => $currency->currency,
                            'currency_icon' => $currency->icon,
                            'instructor_revenue' => $instructor_payout,
                            'created_at'  => \Carbon\Carbon::now()->toDateTimeString(),
                            'updated_at'  => \Carbon\Carbon::now()->toDateTimeString(),
                            ]
                        );
                    }

                }

	        if($created_order){
	        	if (env('MAIL_USERNAME')!=null) {
					try{
						
						/*sending email*/
						$x = 'You are successfully enrolled in a course';
						$order = $created_order;
						Mail::to(Auth::User()->email)->send(new SendOrderMail($x, $order));


					}catch(\Swift_TransportException $e){
						header( "refresh:5;url=./" );
						dd("Payment Successfully ! but Invoice will not sent because admin not updated the mail setting in admin dashboard ! Redirecting you to homepage... !");
					}
				}
			}

			if($created_order){
				// Notification when user enroll
		        $cor = Course::where('id', $cart->course_id)->first();

		        $course = [
		          'title' => $cor->title,
		          'image' => $cor->preview_image,
		        ];

		        $enroll = Order::where('course_id', $cart->course_id)->get();

		        if(!$enroll->isEmpty())
		        {
		            foreach($enroll as $enrol)
		            {
		                $user = User::where('id', $enrol->user_id)->get();
		                Notification::send($user,new UserEnroll($course));
		            }
		        }
			}
			
		}
        

	    \Session::flash('success', 'Payment success');
		    return Redirect::route('home');
	}

}
