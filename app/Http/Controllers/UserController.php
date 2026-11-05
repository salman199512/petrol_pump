<?php
namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private function authorizeAdmin(Request $request): void { abort_unless($request->user()->role==='Super Admin'||$request->user()->hasPermission('users.manage'),403); }
    private function authorizeView(Request $request): void { abort_unless($request->user()->role==='Super Admin'||$request->user()->hasPermission('users.view'),403); }
    private function stationIds(Request $request){return $request->user()->role==='Super Admin'?Station::where('active',true)->pluck('id'):$request->user()->stations()->where('stations.active',true)->pluck('stations.id');}
    private function authorizeTarget(Request $request,User $target):void{$this->authorizeAdmin($request);abort_unless($request->user()->role==='Super Admin'&&$target->role!=='Super Admin'||$request->user()->role==='Super Admin'||($target->role!=='Super Admin'&&$target->stations()->whereIn('stations.id',$this->stationIds($request))->exists()),404);}
    public function index(Request $request){$this->authorizeView($request);$ids=$this->stationIds($request);$users=User::with(['stations','roleRecord'])->when($request->user()->role!=='Super Admin',fn($q)=>$q->whereHas('stations',fn($stationQuery)=>$stationQuery->whereIn('stations.id',$ids)))->orderBy('name')->paginate(50);return view('users.index',compact('users'));}
    public function create(Request $request){$this->authorizeAdmin($request);$user=new User(['active'=>true,'role'=>'Operator','role_id'=>Role::where('name','Operator')->value('id')]);$stations=Station::whereIn('id',$this->stationIds($request))->orderBy('name')->get();$roles=Role::where('active',true)->when($request->user()->role!=='Super Admin',fn($q)=>$q->where('name','<>','Super Admin'))->orderBy('name')->get();return view('users.form',compact('user','stations','roles'));}
    public function edit(Request $request,User $user){$this->authorizeTarget($request,$user);$user->load(['stations','roleRecord']);$stations=Station::whereIn('id',$this->stationIds($request))->orderBy('name')->get();$roles=Role::where('active',true)->when($request->user()->role!=='Super Admin',fn($q)=>$q->where('name','<>','Super Admin'))->orderBy('name')->get();return view('users.form',compact('user','stations','roles'));}
    public function store(Request $request){$this->authorizeAdmin($request);$data=$this->validated($request);$role=Role::findOrFail($data['role_id']);abort_unless($request->user()->role==='Super Admin'||$role->name!=='Super Admin',403);abort_unless(collect($data['station_ids']??[])->every(fn($id)=>$this->stationIds($request)->contains((int)$id)),403);$user=DB::transaction(function()use($request,$data,$role){$user=User::create(['name'=>$data['name'],'email'=>$data['email'],'mobile'=>$data['mobile']??null,'role'=>$role->name,'role_id'=>$role->id,'password'=>Hash::make($data['password']),'active'=>true]);$user->stations()->sync($data['station_ids']??[]);$this->audit($request,'user_created',$user);return $user;});return redirect()->route('users.index')->with('success',"{$user->name} was added as {$user->role}.");}
    public function update(Request $request,User $user){$this->authorizeTarget($request,$user);$data=$this->validated($request,$user);$role=Role::findOrFail($data['role_id']);abort_unless($request->user()->role==='Super Admin'||$role->name!=='Super Admin',403);abort_unless(collect($data['station_ids']??[])->every(fn($id)=>$this->stationIds($request)->contains((int)$id)),403);$active=$request->boolean('active');if($user->is($request->user()) && ($role->name!==$user->role || !$active))throw ValidationException::withMessages(['role_id'=>'Use your profile settings to update your account; your own role and access cannot be changed here.']);if($user->role==='Super Admin' && (!$active||$role->name!=='Super Admin'))$this->protectLastAdmin($user);DB::transaction(function()use($request,$data,$user,$active,$role){$user->fill(['name'=>$data['name'],'email'=>$data['email'],'mobile'=>$data['mobile']??null,'role'=>$role->name,'role_id'=>$role->id,'active'=>$active]);if(!empty($data['password']))$user->password=Hash::make($data['password']);$user->save();$user->stations()->sync($data['station_ids']??[]);$this->audit($request,'user_updated',$user);});return redirect()->route('users.index')->with('success','User details were updated.');}
    public function deactivate(Request $request,User $user){$this->authorizeTarget($request,$user);abort_if($user->is($request->user()),422,'You cannot deactivate your own account.');if($user->role==='Super Admin')$this->protectLastAdmin($user);$user->update(['active'=>false]);$this->audit($request,'user_deactivated',$user);return back()->with('success','User was deactivated.');}
    private function validated(Request $request,?User $user=null): array
    {
        $data=$request->validate(['name'=>'required|string|max:255','email'=>['required','email','max:255',Rule::unique('users','email')->ignore($user?->id)],'mobile'=>['nullable','string','max:30',Rule::unique('users','mobile')->ignore($user?->id)],'role_id'=>['required','integer','exists:roles,id'],'password'=>[$user?'nullable':'required','string','min:8','confirmed'],'station_ids'=>'array','station_ids.*'=>'integer|distinct|exists:stations,id','active'=>'sometimes|boolean']);
        $role=Role::find($data['role_id']);if(!$role?->active)throw ValidationException::withMessages(['role_id'=>'Select an active role.']);
        if($role->name!=='Super Admin' && empty($data['station_ids'])) throw ValidationException::withMessages(['station_ids'=>'Assign at least one station to this user.']);
        if(!empty($data['station_ids']) && Station::whereIn('id',$data['station_ids'])->where('active',true)->count()!==count($data['station_ids']))throw ValidationException::withMessages(['station_ids'=>'Select only active stations.']);
        return $data;
    }
    private function protectLastAdmin(User $user): void {if(User::where('role','Super Admin')->where('active',true)->count()<=1)throw ValidationException::withMessages(['role'=>'Keep at least one active Super Admin account.']);}
    private function audit(Request $request,string $action,User $user): void { DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'action'=>$action,'entity_type'=>'user','entity_id'=>$user->id,'details'=>json_encode(['role'=>$user->role]),'created_at'=>now(),'updated_at'=>now()]); }
}
