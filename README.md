Fileupload - PHP安全文件上传系统
一个安全可靠的PHP文件上传系统，采用现代密码哈希技术保护账户安全，可作为个人网盘、文件备份系统或公司内部文件上传平台使用。

功能特性
🔐 ​​安全认证系统​​：使用bcrypt算法存储密码哈希，防止密码泄露
📁 ​​自动目录管理​​：系统自动创建必要的文件存储目录
📄 ​​账户文件存储​​：用户账户信息存储在加密的文本文件中
📊 ​​日志记录​​：所有操作自动记录到日志文件中
🚀 ​​高效上传​​：支持多文件同时上传
🛡️ ​​安全防护​​：内置密码哈希验证和时序攻击防护
系统结构
├── /1/                  - 账户配置目录
│   └── user_accounts.txt - 用户账户存储文件（格式：用户名:哈希值）
│
├── /files/              - 上传文件存储目录（自动创建）
├── /logs/               - 系统日志目录（自动创建）
├── upload.php           - 主程序文件
├── README.md            - 项目文档
└── LICENSE              - 开源许可证
快速开始
环境要求
PHP ≥ 7.0（推荐 PHP 7.3+）
启用文件上传功能（php.ini中file_uploads = On）
启用password_hash函数支持
安装步骤
克隆仓库到您的web目录：
git clone https://github.com/tiny-lab-hf/Fileupload.git
设置存储目录权限：
chmod -R 775 /1/ /files/ /logs/
访问上传页面：
http://your-domain.com/Fileupload/upload.php
账户管理
默认账户
系统首次使用时，会自动创建管理员账户：

用户名：admin
密码：admin123
​​重要​​：首次登录后请立即修改密码！

添加新账户
编辑/1/user_accounts.txt文件
添加新行，格式为：用户名:密码哈希
使用以下PHP代码生成密码哈希：
<?php
$password = "your_secure_password";
$hash = password_hash($password, PASSWORD_DEFAULT);
echo $hash;
?>
密码安全特性
使用password_hash()生成bcrypt哈希（以$2y$开头）
默认cost参数为10（可在10-31间调整，值越高越安全但性能要求越高）
使用password_verify()进行安全验证，防止时序攻击
自动处理盐值存储（包含在哈希值中）
使用说明
访问upload.php页面
使用您的账户登录
选择要上传的文件（支持多选）
点击"上传"按钮提交文件
上传成功后可在/files/目录查看文件
所有操作记录存储在/logs/目录中
安全配置建议
​​修改默认账户​​：
登录后立即修改admin密码
删除或禁用不必要的账户
​​增强密码强度​​：
// 在upload.php中提高cost参数
$options = ['cost' => 12];
$hash = password_hash($password, PASSWORD_DEFAULT, $options);
​​保护账户文件​​：
chmod 600 /1/user_accounts.txt
​​定期轮换日志​​：
设置cron任务定期归档/清理日志文件
技术细节
密码存储格式
用户名:哈希值
示例： 
admin:$2y$10$X7t3d4qM7s4eXoG2eR4d8.Sz9bL2s3dK8j6G3h9JkL1oP9qW0rT4y
哈希结构解析
$2y$10$X7t3d4qM7s4eXoG2eR4d8.
├─┬┘ ├┘ └───────────────────────
 │  │        │
 │  │        └─ 22字符盐值
 │  └─ cost参数（2^10次迭代）
 └─ 算法标识（bcrypt）
许可证
本项目采用 MIT License 开源协议

注意事项
定期检查/logs/目录中的安全日志
重要文件建议额外加密存储
公开部署时建议添加HTTPS支持
监控/files/目录使用情况，避免存储空间耗尽
​​项目维护​​：公司内部文件扫描上传系统
​​问题反馈​​：请通过GitHub Issues提交问题报告
