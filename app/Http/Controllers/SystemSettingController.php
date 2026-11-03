<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SystemSettingController extends Controller
{
    private const FIELDS=['company_name','tagline','email','phone','alternate_phone','website','address','city','state','postal_code','country','gst_number','currency','timezone','footer_note'];
    public function edit(Request $request)
    {
        abort_unless($request->user()->role==='Super Admin'||$request->user()->hasPermission('settings.manage'),403);
        $settings=DB::table('system_settings')->pluck('value','key');
        return view('settings.edit',compact('settings'));
    }
    public function logo()
    {
        $path=DB::table('system_settings')->where('key','logo_path')->value('value');
        abort_unless($path&&Storage::disk('public')->exists($path),404);
        return Storage::disk('public')->response($path);
    }
    public function update(Request $request)
    {
        abort_unless($request->user()->role==='Super Admin'||$request->user()->hasPermission('settings.manage'),403);
        $data=$request->validate(['company_name'=>'required|string|max:150','tagline'=>'nullable|string|max:180','email'=>'nullable|email|max:190','phone'=>'nullable|string|max:40','alternate_phone'=>'nullable|string|max:40','website'=>'nullable|url|max:190','address'=>'nullable|string|max:500','city'=>'nullable|string|max:100','state'=>'nullable|string|max:100','postal_code'=>'nullable|string|max:20','country'=>'nullable|string|max:100','gst_number'=>'nullable|string|max:30','currency'=>'required|string|size:3','timezone'=>'required|string|max:60','footer_note'=>'nullable|string|max:240','logo'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:2048']);
        if($request->hasFile('logo')){$old=DB::table('system_settings')->where('key','logo_path')->value('value');if($old)Storage::disk('public')->delete($old);$data['logo_path']=$request->file('logo')->store('company','public');}
        unset($data['logo']);
        DB::transaction(function()use($data){foreach($data as $key=>$value)DB::table('system_settings')->updateOrInsert(['key'=>$key],['value'=>$value,'updated_at'=>now(),'created_at'=>now()]);});
        DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'action'=>'company_settings_updated','entity_type'=>'system_settings','entity_id'=>null,'details'=>json_encode(['fields'=>array_keys($data)]),'created_at'=>now(),'updated_at'=>now()]);
        return back()->with('success','Company profile and branding saved.');
    }
}
