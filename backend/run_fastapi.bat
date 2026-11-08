@echo off
setlocal enableextensions

rem Paths
set "BACKEND_DIR=%~dp0"
set "ROOT_DIR=%~dp0.."

rem Change to project root so that 'backend.main' is importable
cd /d "%ROOT_DIR%"

rem Activate virtual env (expects backend\waste_classification_env)
if exist "%BACKEND_DIR%waste_classification_env\Scripts\activate.bat" (
  call "%BACKEND_DIR%waste_classification_env\Scripts\activate.bat"
) else (
  echo [WARN] Virtual env not found: waste_classification_env\Scripts\activate.bat
  echo        Using system Python. Make sure dependencies are installed.
)

rem ---- Environment for training/inference ----
set "ADMIN_TOKEN=changeme"
set "TRAIN_SCRIPT=%~dp0train.py"
set "DATA_ROOT=%~dp0..\data\prepared"
set "MODELS_DIR=%ROOT_DIR%\models"
rem set "WEIGHTS_PATH=%MODELS_DIR%\v2\best.pt"  ^<-- ใช้ค่าเริ่มต้นในโค้ดได้ หากต้องการชี้เฉพาะให้ uncomment

rem ---- Optional: free port 8000 before start ----
for /f "tokens=5" %%p in ('netstat -ano ^| findstr :8000') do (
  echo [INFO] Killing process on port 8000 ^(PID %%p^)
  taskkill /PID %%p /F >nul 2>&1
)

rem ---- Ensure Python deps ----
echo [INFO] Installing base requirements (if needed)
pip install -r backend\requirements.txt --upgrade --quiet

rem Check timm (needed for ConvNeXt-Tiny)
python -c "import importlib,sys; sys.exit(0 if importlib.util.find_spec('timm') else 1)" >nul 2>&1
if errorlevel 1 (
  echo [INFO] Installing timm ...
  pip install timm -q
)

rem Check torch/vision; install CPU wheels by default
python -c "import importlib,sys; sys.exit(0 if importlib.util.find_spec('torch') else 1)" >nul 2>&1
if errorlevel 1 (
  echo [INFO] Installing PyTorch ^(CPU^) ...
  pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu -q
)

echo [INFO] Starting FastAPI (press Ctrl+C to stop)
python -m uvicorn backend.main:app --reload --host 0.0.0.0 --port 8000

endlocal
exit
