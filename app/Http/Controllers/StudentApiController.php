<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\MySchedule;
use Exception;

class StudentApiController extends Controller
{
    public function syncStudentData(Request $request)
    {
        try {
            // 1. تسجيل الدخول
            $loginResponse = Http::post('https://quiztoxml.ucas.edu.ps/api/login', [
                'username' => $request->username,
                'password' => $request->password,
            ]);

            $loginData = $loginResponse->json();

            if (!$loginResponse->successful() || (isset($loginData['success']) && $loginData['success'] === false)) {
                return response()->json("كلمة المرور او اسم المستخدم خطا", 401);
            }

            $studentName = $loginData['data']['user_ar_name'] ?? 'احمد الزعانين';
            $studentId = $loginData['data']['user_id'];
            $token = $loginData['Token'];

            // 2. جلب الجدول (استخدام asForm ضروري جداً هنا)
            $tableResponse = Http::asForm()->post('https://quiztoxml.ucas.edu.ps/api/get-table', [
                'user_id' => $studentId,
                'token'   => $token,
            ]);

            $tableData = $tableResponse->json();

            // بناءً على الـ dump: البيانات موجودة في $tableData['data']
            $courses = $tableData['data'] ?? [];

            if (empty($courses)) {
                return response()->json([
                    "message" => "فشل جلب البيانات: مصفوفة المواد فارغة",
                    "debug_info" => $tableData 
                ], 400);
            }

            // 3. التخزين في الجدول الجديد my_schedules
            MySchedule::truncate(); 

            foreach ($courses as $course) {
                // التأكد من وجود اسم المادةsubject_name كما في الـ dump
                if (isset($course['subject_name'])) {
                    
                    $timeDetails = $this->extractDetails($course);

                    MySchedule::create([
                        'student_name' => $studentName,
                        'course_code'  => $course['subject_no'] ?? 'N/A',
                        'course_name'  => $course['subject_name'] ?? 'N/A',
                        'day'          => $timeDetails['day'],
                        'time'         => $timeDetails['time'],
                    ]);
                }
            }

            $finalCount = MySchedule::count();

            return response()->json([
                'status' => 'success',
                'message' => 'تمت المزامنة بنجاح يا ' . $studentName,
                'db_count' => $finalCount
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'خطأ في الكود: ' . $e->getMessage()
            ], 500);
        }
    }

    private function extractDetails($course)
    {
        $day = 'N/A';
        $time = 'N/A';
        // الأيام كما ظهرت في الـ dump: S, N, M, T, W, R
        $map = ['S'=>'السبت','N'=>'الأحد','M'=>'الاثنين','T'=>'الثلاثاء','W'=>'الأربعاء','R'=>'الخميس'];
        
        foreach ($map as $key => $name) {
            if (!empty($course[$key]) && $course[$key] !== "") {
                $day = $name;
                $time = $course[$key];
                break; 
            }
        }
        return ['day' => $day, 'time' => $time];
    }
}