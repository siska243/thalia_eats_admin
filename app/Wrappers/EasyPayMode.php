<?php

namespace App\Wrappers;
enum EasyPayMode: string{
    case SANDBOX='sandbox';
    case V1="v1";
    case TOKEN="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpYXQiOjE2OTM1NjIzNDcsImp0aSI6IjRRRlRjWWF1Zm03MmNpZU41SXk2UW0zeEN0N0xXUGgzK0FCQkptUlVyaVE9IiwiaXNzIjoiZUNvbVNBUy1TZWN1cmVkU2VydmVyIiwibmJmIjoxNjkzNTYyMzU3LCJleHAiOjE2OTM1Njk1NTcsImRhdGEiOnsidXNlcklkIjo3NTd9fQ.E5JKL4RTvt1rr7yq0mJi7shrQEKB9hHtPOY9Qk_R-0w";
    case CID="Y05BeXZ4MEk1WklPd2g0emN2cTNiSy9aKy8rSno5L0QvcTBsWlhQZEhSNWlNV2ZWYnc9PQ==";
}
