<?php

declare(strict_types=1);
/**
 * This file is part of MineAdmin.
 *
 * @link     https://www.mineadmin.com
 * @document https://doc.mineadmin.com
 * @contact  root@imoi.cn
 * @license  https://github.com/mineadmin/MineAdmin/blob/master/LICENSE
 */

namespace App\Service\Permission;

use App\Model\Permission\User;
use App\Repository\Permission\UserRepository;
use App\Service\AbstractCrudService;
use Hyperf\Cache\Annotation\Cacheable;
use Hyperf\Cache\Annotation\CacheEvict;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * 用户业务
 * Class UserService.
 * @implements AbstractCrudService<User>
 */
class UserService extends AbstractCrudService
{

    public function __construct(
        protected ContainerInterface $container,
        protected UserRepository $repository,
        protected MenuService $systemMenuService,
        protected RoleService $systemRoleService
    ) {}

    /**
     * 获取用户信息.
     */
    public function getInfo(?int $userId = null): array
    {
        return $this->repository->findById($userId)->toArray();
    }

    /**
     * 新增用户.
     */
    public function save(array $data): mixed
    {
        $id = $this->repository->save($this->handleData($data));
        $data['id'] = $id;
        event(new UserAdd($data));
        return $id;
    }

    /**
     * 更新用户信息.
     */
    #[CacheEvict(prefix: 'loginInfo', value: 'userId_#{id}')]
    public function update(mixed $id, array $data): bool
    {
        if (isset($data['username'])) {
            unset($data['username']);
        }
        if (isset($data['password'])) {
            unset($data['password']);
        }
        return $this->repository->update($id, $this->handleData($data));
    }

    /**
     * 获取在线用户.
     * @throws ContainerExceptionInterface
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     */
    public function getOnlineUserPageList(array $params = []): array
    {
        $redis = redis();
        $key = sprintf('%sToken:*', config('cache.default.prefix'));
        $jwt = $this->container->get(JWT::class);
        $blackList = $this->container->get(JWT::class)->blackList;
        $userIds = [];
        $iterator = null;

        while (false !== ($users = $redis->scan($iterator, $key, 100))) {
            foreach ($users as $user) {
                // 如果是已经加入到黑名单的就代表不是登录状态了
                // 重写正则 用来 匹配 多点登录 使用的token的key
                if (! $this->hasTokenBlack($redis->get($user)) && preg_match('/:(\d+)(:|$)/', $user, $match) && isset($match[1])) {
                    $userIds[] = $match[1];
                }
            }
            unset($users);
        }

        if (empty($userIds)) {
            return [];
        }

        return $this->getPageList(array_merge(['userIds' => $userIds], $params));
    }

    /**
     * 删除用户.
     */
    public function delete(array $ids): bool
    {
        if (! empty($ids)) {
            if (($key = array_search(env('SUPER_ADMIN'), $ids)) !== false) {
                unset($ids[$key]);
            }
            $result = $this->repository->delete($ids);
            event(new UserDelete($ids));
            return $result;
        }

        return false;
    }

    /**
     * 真实删除用户.
     */
    public function realDelete(array $ids): bool
    {
        if (! empty($ids)) {
            if (($key = array_search(env('SUPER_ADMIN'), $ids)) !== false) {
                unset($ids[$key]);
            }
            $result = $this->repository->realDelete($ids);
            event(new UserDelete($ids));
            return $result;
        }

        return false;
    }

    /**
     * 强制下线用户.
     * @throws ContainerExceptionInterface
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws \RedisException
     */
    public function kickUser(string $id): bool
    {
        $redis = redis();
        // 保证获取到所有token，方便一次性全部下线。
        $key = sprintf('%sToken:%s*', config('cache.default.prefix'), $id);
        while (false !== ($users = $redis->scan($iterator, $key, 100))) {
            $jwt = $this->container->get(JWT::class);
            foreach ($users as $user) {
                $token = $redis->get($user);
                if (! is_string($token)) {
                    continue;
                }
                $scene = $jwt->getParserData($token)['jwt_scene'];
                $jwt->logout($token, $scene);
                $redis->del($user);
            }
            unset($users);
        }
        return true;
    }

    /**
     * 初始化用户密码
     */
    public function initUserPassword(int $id, string $password = '123456'): bool
    {
        return $this->repository->initUserPassword($id, $password);
    }

    /**
     * 清除用户缓存.
     * @throws \RedisException
     */
    public function clearCache(string $id): bool
    {
        $redis = redis();
        $prefix = config('cache.default.prefix');

        $iterator = null;
        while (false !== ($configKey = $redis->scan($iterator, $prefix . 'config:*', 100))) {
            $redis->del($configKey);
        }
        while (false !== ($dictKey = $redis->scan($iterator, $prefix . 'system:dict:*', 100))) {
            $redis->del($dictKey);
        }
        $redis->del([$prefix . 'crontab', $prefix . 'modules']);

        return $redis->del("{$prefix}loginInfo:userId_{$id}") > 0;
    }

    /**
     * 设置用户首页.
     * @throws \RedisException
     */
    public function setHomePage(array $params): bool
    {
        $res = ($this->repository->model)::query()
            ->where('id', $params['id'])
            ->update(['dashboard' => $params['dashboard']]) > 0;

        $this->clearCache((string) $params['id']);
        return $res;
    }

    /**
     * 用户更新个人资料.
     * @throws \RedisException
     */
    public function updateInfo(array $params): bool
    {
        if (! isset($params['id'])) {
            return false;
        }

        $id = $params['id'];
        unset($params['id'], $params['password']);
        $this->clearCache((string) $id);

        return $this->repository->update($id, $params);
    }

    /**
     * 用户修改密码
     */
    public function modifyPassword(array $params): bool
    {
        return $this->repository->initUserPassword(user()->getId(), $params['newPassword']);
    }

    /**
     * 通过 id 列表获取用户基础信息.
     */
    public function getUserInfoByIds(array $ids): array
    {
        return $this->repository->getUserInfoByIds($ids);
    }

    /**
     * 获取缓存用户信息.
     */
    #[Cacheable(prefix: 'loginInfo', value: 'userId_#{id}', ttl: 0)]
    protected function getCacheInfo(int $id): array
    {
        $user = $this->repository->model->find($id);
        $user->addHidden('deleted_at', 'password');
        $data['user'] = $user->toArray();
        if (user()->isSuperAdmin()) {
            $data['roles'] = ['superAdmin'];
            $data['routers'] = $this->sysMenuService->repository->getSuperAdminRouters();
            $data['codes'] = ['*'];
        } else {
            $roles = $this->sysRoleService->repository->getMenuIdsByRoleIds($user->roles()->pluck('id')->toArray());
            $ids = $this->filterMenuIds($roles);
            $data['roles'] = $user->roles()->pluck('code')->toArray();
            $data['routers'] = $this->sysMenuService->repository->getRoutersByIds($ids);
            $data['codes'] = $this->sysMenuService->repository->getMenuCode($ids);
        }

        return $data;
    }

    /**
     * 过滤通过角色查询出来的菜单id列表，并去重.
     */
    protected function filterMenuIds(array &$roleData): array
    {
        $ids = [];
        foreach ($roleData as $val) {
            foreach ($val['menus'] as $menu) {
                $ids[] = $menu['id'];
            }
        }
        unset($roleData);
        return array_unique($ids);
    }

    /**
     * 处理提交数据.
     * @param mixed $data
     */
    protected function handleData(array $data): array
    {
        if (! is_array($data['role_ids'])) {
            $data['role_ids'] = explode(',', $data['role_ids']);
        }
        if (($key = array_search(env('ADMIN_ROLE'), $data['role_ids'])) !== false) {
            unset($data['role_ids'][$key]);
        }
        if (! empty($data['post_ids']) && ! is_array($data['post_ids'])) {
            $data['post_ids'] = explode(',', $data['post_ids']);
        }
        if (! empty($data['dept_ids']) && ! is_array($data['dept_ids'])) {
            $data['dept_ids'] = explode(',', $data['dept_ids']);
        }
        return $data;
    }

    private function hasTokenBlack(string $token): bool
    {
        # token解析的数据有scene信息，只需要判断当前token在对应场景下是否有黑名单
        $jwt = $this->container->get(JWT::class);
        $scene = $jwt->getParserData($token)['jwt_scene'];
        $scenes = array_keys(config('jwt.scene'));
        $jti = $jwt->getParserData($token)['jti'];
        if (in_array($scene, $scenes) && $jwt->setScene($scene)->blackList->hasTokenBlack(
            $jwt->getParserData($token),
            $jwt->getSceneConfig($scene)
        )) {
            return true;
        }
        return false;
    }
}